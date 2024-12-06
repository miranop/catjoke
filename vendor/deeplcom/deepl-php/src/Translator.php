<?php

// Copyright 2022 DeepL SE (https://www.deepl.com)
// Use of this source code is governed by an MIT
// license that can be found in the LICENSE file.

namespace DeepL;

/**
 * Wrapper for the DeepL API for language translation.
 * Create an instance of Translator to use the DeepL API.
 */
class Translator
{
    /**
     * Library version.
     */
    public const VERSION = '0.4.1';

    /**
     * Implements all HTTP requests and retries.
     */
    private $client;

    /**
     * Construct a Translator object wrapping the DeepL API using your authentication key.
     * This does not connect to the API, and returns immediately.
     * @param string $authKey Authentication key as specified in your account.
     * @param array $options Additional options controlling Translator behaviour.
     * @throws DeepLException
     * @see TranslatorOptions for a list of available request options.
     */
    public function __construct(string $authKey, array $options = [])
    {
        if ($authKey === '') {
            throw new DeepLException('authKey must be a non-empty string');
        }

        $serverUrl = $options[TranslatorOptions::SERVER_URL] ??
            (self::isAuthKeyFreeAccount($authKey) ? TranslatorOptions::DEFAULT_SERVER_URL_FREE
                : TranslatorOptions::DEFAULT_SERVER_URL);
        if (!is_string($serverUrl) || strlen($serverUrl) == 0) {
            throw new DeepLException('If specified, ' .
                TranslatorOptions::SERVER_URL . ' option must be a non-empty string.');
        } elseif (substr($serverUrl, -1) === "/") { // Remove trailing slash if present
            $serverUrl = substr($serverUrl, 0, strlen($serverUrl) - 1);
        }

        $headers = array_replace(
            [
                'Authorization' => "DeepL-Auth-Key $authKey",
                'User-Agent' => 'deepl-php/0.4.1',
            ],
            $options[TranslatorOptions::HEADERS] ?? []
        );

        $timeout = $options[TranslatorOptions::TIMEOUT] ?? TranslatorOptions::DEFAULT_TIMEOUT;

        $maxRetries = $options[TranslatorOptions::MAX_RETRIES] ?? TranslatorOptions::DEFAULT_MAX_RETRIES;

        $logger = $options[TranslatorOptions::LOGGER] ?? null;

        $this->client = new HttpClient($serverUrl, $headers, $timeout, $maxRetries, $logger);
    }

    /**
     * Queries character and document usage during the current billing period.
     * @return Usage
     * @throws DeepLException
     */
    public function getUsage(): Usage
    {
        $response = $this->client->sendRequestWithBackoff('POST', '/v2/usage');
        $this->checkStatusCode($response);
        list(, $content) = $response;
        return new Usage($content);
    }

    /**
     * Queries source languages supported by DeepL API.
     * @return Language[] Array of Language objects containing available source languages.
     * @throws DeepLException
     */
    public function getSourceLanguages(): array
    {
        return $this->getLanguages(false);
    }

    /**
     * Queries target languages supported by DeepL API.
     * @return Language[] Array of Language objects containing available target languages.
     * @throws DeepLException
     */
    public function getTargetLanguages(): array
    {
        return $this->getLanguages(true);
    }

    /**
     * Translates specified text string or array of text strings into the target language.
     * @param $texts string|string[] A single string or array of strings containing input texts to translate.
     * @param string|null $sourceLang Language code of input text language, or null to use auto-detection.
     * @param string $targetLang Language code of language to translate into.
     * @param array $options Translation options to apply. See \DeepL\TranslateTextOptions.
     * @return TextResult|TextResult[] A TextResult or array of TextResult objects containing translated texts.
     * @throws DeepLException
     * @see \DeepL\TranslateTextOptions
     */
    public function translateText($texts, ?string $sourceLang, string $targetLang, array $options = [])
    {
        $params = $this->buildBodyParams(
            $sourceLang,
            $targetLang,
            $options[TranslateTextOptions::FORMALITY] ?? null,
            $options[TranslateTextOptions::GLOSSARY] ?? null
        );
        $this->validateAndAppendTexts($params, $texts);
        $this->validateAndAppendTextOptions($params, $options);

        $response = $this->client->sendRequestWithBackoff(
            'POST',
            '/v2/translate',
            [HttpClient::OPTION_PARAMS => $params]
        );
        $this->checkStatusCode($response);

        list(, $content) = $response;
        $decoded = json_decode($content, true);
        $textResults = [];
        foreach ($decoded['translations'] as $textResult) {
            $textField = $textResult['text'];
            $detectedSourceLang = $textResult['detected_source_language'];
            $textResults[] = new TextResult($textField, $detectedSourceLang);
        }
        return is_array($texts) ? $textResults : $textResults[0];
    }

    /**
     * Uploads specified document to DeepL to translate into given target language, waits for translation to complete,
     * then downloads translated document to specified output path.
     * @param string $inputFile String containing file path of document to be translated.
     * @param string $outputFile String containing file path to create translated document.
     * @param string|null $sourceLang Language code of input document, or null to use auto-detection.
     * @param string $targetLang Language code of language to translate into.
     * @param array $options Translation options to apply. See \DeepL\TranslateDocumentOptions.
     * @return DocumentStatus DocumentStatus object for the completed translation. You can use the billedCharacters
     *     property to check how many characters were billed for the document.
     * @throws DocumentTranslationException If a file already exists at the output file path, or if any error occurs
     * during document upload, translation or download. The `documentHandle` property of the exception, if not null,
     * may be used to recover the document ID and key of an in-progress translation.
     * @see \DeepL\TranslateDocumentOptions
     */
    public function translateDocument(
        string $inputFile,
        string $outputFile,
        ?string $sourceLang,
        string $targetLang,
        array $options = []
    ): DocumentStatus {
        $handle = null;
        if (file_exists($outputFile)) {
            throw new DocumentTranslationException("File already exists at output file path $outputFile");
        }
        try {
            $handle = $this->uploadDocument($inputFile, $sourceLang, $targetLang, $options);
            $status = $this->waitUntilDocumentTranslationComplete($handle);
            $this->downloadDocument($handle, $outputFile);
            return $status;
        } catch (DeepLException $error) {
            if (file_exists($outputFile)) {
                unlink($outputFile);
            }
            $message = 'Error occurred while translating document: ' . ($error->getMessage() ?? 'unknown error');
            throw new DocumentTranslationException($message, $error->getCode(), $error, $handle);
        }
    }

    /**
     * Uploads specified document to DeepL to translate into target language, and returns handle associated with the
     * document.
     * @param string $inputFile String containing file path of document to be translated.
     * @param string|null $sourceLang Language code of input document, or null to use auto-detection.
     * @param string $targetLang Language code of language to translate into.
     * @param array $options Translation options to apply. See \DeepL\TranslateDocumentOptions.
     * @return DocumentHandle Handle associated with the in-progress document translation.
     * @throws DeepLException
     */
    public function uploadDocument(
        string $inputFile,
        ?string $sourceLang,
        string $targetLang,
        array $options = []
    ): DocumentHandle {
        $params = $this->buildBodyParams(
            $sourceLang,
            $targetLang,
            $options[TranslateDocumentOptions::FORMALITY] ?? null,
            $options[TranslateDocumentOptions::GLOSSARY] ?? null
        );

        $response = $this->client->sendRequestWithBackoff(
            'POST',
            '/v2/document',
            [
                HttpClient::OPTION_PARAMS => $params,
                HttpClient::OPTION_FILE => $inputFile,
            ]
        );
        $this->checkStatusCode($response);

        list(, $content) = $response;

        $json = json_decode($content, true);
        $documentId = $json['document_id'];
        $documentKey = $json['document_key'];
        return new DocumentHandle($documentId, $documentKey);
    }

    /**
     * Retrieves the status of the document translation associated with the given document handle.
     * @param DocumentHandle $handle Document handle associated with document.
     * @return DocumentStatus The document translation status.
     * @throws DeepLException
     */
    public function getDocumentStatus(DocumentHandle $handle): DocumentStatus
    {
        $response = $this->client->sendRequestWithBackoff(
            'POST',
            "/v2/document/$handle->documentId",
            [HttpClient::OPTION_PARAMS => ['document_key' => $handle->documentKey]]
        );
        $this->checkStatusCode($response);
        list(, $content) = $response;
        return new DocumentStatus($content);
    }

    /**
     * Downloads the translated document associated with the given document handle to the specified output file path.
     * @param DocumentHandle $handle Document handle associated with document.
     * @param string $outputFile String containing file path to create translated document.
     * @throws DeepLException
     */
    public function downloadDocument(DocumentHandle $handle, string $outputFile): void
    {
        if (file_exists($outputFile)) {
            throw new DeepLException("File already exists at output file path $outputFile");
        }
        try {
            $response = $this->client->sendRequestWithBackoff(
                'POST',
                "/v2/document/$handle->documentId/result",
                [
                    HttpClient::OPTION_PARAMS => ['document_key' => $handle->documentKey],
                    HttpClient::OPTION_OUTFILE => $outputFile,
                ]
            );
            $this->checkStatusCode($response, true);
        } catch (DeepLException $error) {
            if (file_exists($outputFile)) {
                unlink($outputFile);
            }
            throw $error;
        }
    }

    /**
     * Returns when the given document translation completes, or throws an exception if there was an error
     * communicating with the DeepL API or the document translation failed.
     * @param DocumentHandle $handle Handle to the document translation.
     * @return DocumentStatus DocumentStatus object for the completed translation. You can use the billedCharacters
     *     property to check how many characters were billed for the document.
     * @throws DeepLException
     */
    public function waitUntilDocumentTranslationComplete(DocumentHandle $handle): DocumentStatus
    {
        $status = $this->getDocumentStatus($handle);
        while (!$status->done() && $status->ok()) {
            // Wait for half of remaining time, limited between 1 and 60 seconds
            $secs = ($status->secondsRemaining || 0) / 2.0 + 1.0;
            $secs = max(1.0, min($secs, 60.0));
            usleep($secs * 1000000);
            $this->client->logInfo("Rechecking document translation status after sleeping for $secs seconds.");
            $status = $this->getDocumentStatus($handle);
        }
        if (!$status->ok()) {
            throw new DeepLException($status->errorMessage ?? 'unknown error');
        }
        return $status;
    }

    /**
     * Queries source or target languages supported by DeepL API.
     * @param bool $target Query target languages if true, source languages otherwise.
     * @return Language[] Array of Language objects containing available languages.
     * @throws DeepLException
     */
    private function getLanguages(bool $target): array
    {
        $response = $this->client->sendRequestWithBackoff(
            'GET',
            '/v2/languages',
            [HttpClient::OPTION_PARAMS => ['type' => $target ? 'target' : null]]
        );
        $this->checkStatusCode($response);
        list(, $content) = $response;

        $decoded = json_decode($content, true);
        $result = [];
        foreach ($decoded as $lang) {
            $name = $lang['name'];
            $code = $lang['language'];
            $supportsFormality = array_key_exists('supports_formality', $lang) ?
                $lang['supports_formality'] : null;
            $result[] = new Language($name, $code, $supportsFormality);
        }
        return $result;
    }

    /**
     * Joins given TagList with commas to form a single comma-delimited string.
     * @param string[]|string $tagList List of tags to join.
     * @return string Tags combined into a comma-delimited string.
     */
    private function joinTagList($tagList): string
    {
        if (is_string($tagList)) {
            return $tagList;
        } else {
            return implode(',', $tagList);
        }
    }

    /**
     * Validates and prepares HTTP parameters for arguments common to text and document translation.
     * @param string|null $sourceLang Source language code, or null to use auto-detection.
     * @param string $targetLang Target language code.
     * @param string|null $formality Formality option, or null if not specified.
     * @param string|null $glossary Glossary ID, or null if not specified.
     * @return array Associative array of HTTP parameters.
     * @throws DeepLException
     */
    private function buildBodyParams(
        ?string $sourceLang,
        string $targetLang,
        ?string $formality,
        ?string $glossary
    ): array {
        $targetLang = LanguageCode::standardizeLanguageCode($targetLang);
        if (isset($sourceLang)) {
            $sourceLang = LanguageCode::standardizeLanguageCode($sourceLang);
        }

        if ($targetLang === 'en') {
            throw new DeepLException('targetLang="en" is deprecated, please use "en-GB" or "en-US" instead.');
        } elseif ($targetLang === 'pt') {
            throw new DeepLException('targetLang="pt" is deprecated, please use "pt-PT" or "pt-BR" instead.');
        }

        $params = ['target_lang' => $targetLang];
        if (isset($sourceLang)) {
            $params['source_lang'] = $sourceLang;
        }
        if (isset($formality)) {
            $formality_str = strtolower($formality);
            if ($formality_str !== 'default') {
                $params['formality'] = $formality_str;
            }
        }
        if (isset($glossary)) {
            $params['glossary_id'] = $glossary;
        }
        return $params;
    }

    /**
     * Validates and appends texts to HTTP request parameters.
     * @param array $params Parameters for HTTP request.
     * @param string|string[] $texts User-supplied texts to be checked.
     * @throws DeepLException
     */
    private function validateAndAppendTexts(array &$params, $texts)
    {
        if (is_array($texts)) {
            foreach ($texts as $text) {
                if (!is_string($text) || strlen($text) === 0) {
                    throw new DeepLException(
                        'texts parameter must be a non-empty string or array of non-empty strings',
                    );
                }
            }
        } else {
            if (!is_string($texts) || strlen($texts) === 0) {
                throw new DeepLException(
                    'texts parameter must be a non-empty string or array of non-empty strings',
                );
            }
        }
        $params['text'] = $texts;
    }

    /**
     * Validates and appends text options to HTTP request parameters.
     * @param array $params Parameters for HTTP request.
     * @param array|null $options Options for translate text request.
     * Note the formality and glossary options are handled separately, because these options overlap with document
     * translation.
     * @throws DeepLException
     */
    private function validateAndAppendTextOptions(array &$params, ?array $options): void
    {
        if ($options === null) {
            return;
        }
        if (isset($options[TranslateTextOptions::SPLIT_SENTENCES])) {
            $split_sentences = strtolower($options[TranslateTextOptions::SPLIT_SENTENCES]);
            switch ($split_sentences) {
                case 'on':
                case 'default':
                    $params[TranslateTextOptions::SPLIT_SENTENCES] = '1';
                    break;
                case 'off':
                    $params[TranslateTextOptions::SPLIT_SENTENCES] = '0';
                    break;
                default:
                    $params[TranslateTextOptions::SPLIT_SENTENCES] = $split_sentences;
                    break;
            }
        }
        if ($options[TranslateTextOptions::PRESERVE_FORMATTING] ?? false) {
            $params[TranslateTextOptions::PRESERVE_FORMATTING] = '1';
        }
        if (isset($options[TranslateTextOptions::TAG_HANDLING])) {
            $params[TranslateTextOptions::TAG_HANDLING] = $options[TranslateTextOptions::TAG_HANDLING];
        }
        if (isset($options[TranslateTextOptions::OUTLINE_DETECTION]) &&
            $options[TranslateTextOptions::OUTLINE_DETECTION] === false) {
            $params[TranslateTextOptions::OUTLINE_DETECTION] = '0';
        }
        if (isset($options[TranslateTextOptions::NON_SPLITTING_TAGS])) {
            $params[TranslateTextOptions::NON_SPLITTING_TAGS] =
                $this->joinTagList($options[TranslateTextOptions::NON_SPLITTING_TAGS]);
        }
        if (isset($options[TranslateTextOptions::SPLITTING_TAGS])) {
            $params[TranslateTextOptions::SPLITTING_TAGS] =
                $this->joinTagList($options[TranslateTextOptions::SPLITTING_TAGS]);
        }
        if (isset($options[TranslateTextOptions::IGNORE_TAGS])) {
            $params[TranslateTextOptions::IGNORE_TAGS] =
                $this->joinTagList($options[TranslateTextOptions::IGNORE_TAGS]);
        }
    }

    /**
     * Checks the HTTP status code, and in case of failure, throws an exception with diagnostic information.
     * @throws DeepLException
     */
    private function checkStatusCode(array $response, bool $inDocumentDownload = false, bool $usingGlossary = false)
    {
        list($statusCode, $content) = $response;

        if (200 <= $statusCode && $statusCode < 400) {
            return;
        }

        $message = '';
        try {
            $json = json_decode($content, true);
            if (isset($json['message'])) {
                $message .= ", message: {$json['message']}";
            }
            if (isset($json['detail'])) {
                $message .= ", detail: {$json['detail']}";
            }
        } catch (\Exception $e) {
            // JSON parsing errors are ignored, and we fall back to the raw response
            $message = ", $content";
        }

        switch ($statusCode) {
            case 403:
                throw new AuthorizationException("Authorization failure, check authentication key$message");
            case 456:
                throw new QuotaExceededException("Quota for this billing period has been exceeded$message");
            case 404:
                if ($usingGlossary) {
                    throw new DeepLException("Glossary not found$message");
                }
                throw new NotFoundException("Not found, check server_url$message");
            case 400:
                throw new DeepLException("Bad request$message");
            case 429:
                throw new TooManyRequestsException(
                    "Too many requests, DeepL servers are currently experiencing high load$message"
                );
            case 503:
                if ($inDocumentDownload) {
                    throw new DocumentNotReadyException("Document not ready$message");
                } else {
                    throw new DeepLException("Service unavailable$message");
                }
                break; // break required by phpcs although it is unnecessary
            default:
                throw new DeepLException(
                    "Unexpected status code: $statusCode $message, content: $content."
                );
        }
    }

    /**
     * Returns true if the specified DeepL Authentication Key is associated with a free account,
     * otherwise false.
     * @param string authKey The authentication key to check.
     * @return bool True if the key is associated with a free account, otherwise false.
     */
    public static function isAuthKeyFreeAccount(string $authKey): bool
    {
        return substr($authKey, -3) === ':fx';
    }
}