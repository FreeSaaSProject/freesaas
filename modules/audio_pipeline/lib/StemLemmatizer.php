<?php
declare(strict_types=1);

/**
 * Line/word lemmatization for key matching (port of recordParser3 stringProcess*).
 */
final class StemLemmatizer
{
    /** @var object|null phpMorphy instance */
    private $morphy;

    /** @var array<string, string> */
    private array $badLemWords;

    /** @var array<string, int> */
    private array $ownNames;

    /** @var array<string, int> */
    private array $stemWords = [];

    /** @var array<string, int> */
    private array $fullWords = [];

    /** @var array<string, int> */
    private array $noStemWords = [];

    /**
     * @param array<string, string> $badLemWords
     * @param array<string, int> $ownNames
     * @param object|null $morphy phpMorphy when available
     */
    public function __construct(array $badLemWords, array $ownNames, $morphy = null)
    {
        $this->badLemWords = $badLemWords;
        $this->ownNames = [];
        foreach ($ownNames as $k => $v) {
            $this->ownNames[mb_strtoupper((string)$k, 'UTF-8')] = (int)$v;
        }
        $this->morphy = $morphy;
    }

    /** @return array<string, int> */
    public function getStemWords(): array
    {
        return $this->stemWords;
    }

    /** @return array<string, int> */
    public function &stemWordsRef(): array
    {
        return $this->stemWords;
    }

    /** @return array<string, int> */
    public function getFullWords(): array
    {
        return $this->fullWords;
    }

    /**
     * @param string|array<int, string>|array<string, string> $textData
     * @return array{contentArray:array<int,int>,contentStartArray:array<int,int>,contentLengthArray:array<int,int>}|false
     */
    public function stringProcess($textData, int $debug = 0)
    {
        if (!$textData) {
            return false;
        }
        if (is_array($textData)) {
            $textData = StemCache::keyTextForStem($textData);
        }
        if (!$textData) {
            return false;
        }
        $lemmaResult = $this->stringProcessLemmas((string)$textData, $debug);
        if ($lemmaResult === false) {
            return false;
        }

        return StemCache::lemmasToContentArrays(
            $lemmaResult['lemmas'],
            $lemmaResult['contentStartArray'],
            $lemmaResult['contentLengthArray'],
            $this->stemWords
        );
    }

    /**
     * @return array{contentArray:array<int,int>,contentStartArray:array<int,int>,contentLengthArray:array<int,int>}|false
     */
    public function stringProcessRecordLine(string $stringPureText, int $debug = 0, int $recordDate = 0, int $idBadge = 0)
    {
        if ($stringPureText === '') {
            return false;
        }
        $cachedLine = StemCache::getLine($stringPureText, $recordDate, $idBadge);
        if (is_array($cachedLine)) {
            return StemCache::lemmasToContentArrays(
                $cachedLine['lemmas'],
                $cachedLine['contentStartArray'],
                $cachedLine['contentLengthArray'],
                $this->stemWords
            );
        }
        $lemmaResult = $this->stringProcessLemmas($stringPureText, $debug);
        if ($lemmaResult === false) {
            return false;
        }
        StemCache::setLine($stringPureText, $lemmaResult, $recordDate, $idBadge);

        return StemCache::lemmasToContentArrays(
            $lemmaResult['lemmas'],
            $lemmaResult['contentStartArray'],
            $lemmaResult['contentLengthArray'],
            $this->stemWords
        );
    }

    /**
     * @return array{lemmas:array<int,string>,contentStartArray:array<int,int>,contentLengthArray:array<int,int>}|false
     */
    public function stringProcessLemmas(string $textData, int $debug = 0)
    {
        if ($textData === '') {
            return false;
        }

        $tryToLemm = 1;
        $lemmasArray = [];
        $contentStartArray = [];
        $contentLengthArray = [];

        $textDataU = mb_strtoupper($textData, 'UTF-8');
        $textDataUArray = explode(' ', $textDataU);
        $symNumber = 0;
        $i = 0;
        $lastPos = 0;

        foreach ($textDataUArray as $wordOriginal) {
            $wordOriginalForSearch = $wordOriginal;
            $word = $wordOriginal;
            $stopLemmStem = 0;

            $isStop = mb_substr($word, 0, 1, 'UTF-8');
            if ($isStop === '#') {
                $stopLemmStem = 1;
            }
            $word = str_replace('#', '', $word);

            // Punctuation → spaces must re-split (otherwise "a.b.c" stays one lemma with spaces).
            $word = str_replace('...', '', $word);
            $word = str_replace(',', '', $word);
            $word = str_replace('.', ' ', $word);
            $word = str_replace(';', ' ', $word);
            $word = str_replace('?', '', $word);
            $word = str_replace('-', ' ', $word);
            $word = str_replace('*', '', $word);
            $word = str_replace('«', '', $word);
            $word = str_replace('»', '', $word);
            $word = str_replace('!', '', $word);
            $word = str_replace('…', '', $word);
            $word = str_replace('"', '', $word);

            $word = trim($word);
            // Locate the original space-token even when punctuation left nothing to lemmatize.
            $wordPos = mb_stripos($textData, $wordOriginalForSearch, $lastPos, 'UTF-8');
            $tokenLen = mb_strlen($wordOriginalForSearch, 'UTF-8');
            if ($wordPos !== false) {
                $tokenEnd = $wordPos + $tokenLen;
            } else {
                $wordPos = $symNumber;
                $tokenEnd = $symNumber + max(1, $tokenLen);
                $symNumber = $tokenEnd + 1;
            }

            if ($word === '') {
                $lastPos = $tokenEnd;
                continue;
            }

            $parts = preg_split('/\s+/u', $word) ?: [];
            $partCount = 0;
            foreach ($parts as $pCheck) {
                if (trim((string)$pCheck) !== '') {
                    $partCount++;
                }
            }
            $searchFrom = $wordPos;
            foreach ($parts as $part) {
                $part = trim((string)$part);
                if ($part === '') {
                    continue;
                }
                // ASR glue / missing spaces: not a real word — log and skip (do not cache).
                if (mb_strlen($part, 'UTF-8') > 255) {
                    error_log(sprintf(
                        '[StemLemmatizer] skip overlong token: len=%d preview=%s line_preview=%s',
                        mb_strlen($part, 'UTF-8'),
                        StemCache::previewToken($part),
                        StemCache::previewToken($textData, 120)
                    ));
                    continue;
                }

                $partStop = $stopLemmStem;
                $lemmed = 0;
                if ($partStop) {
                    if (!isset($this->noStemWords[$part])) {
                        $this->noStemWords[$part] = 1;
                    }
                }
                if (isset($this->noStemWords[$part])) {
                    $partStop = 1;
                }

                if (isset($this->badLemWords[$part])) {
                    $part = $this->badLemWords[$part];
                }
                if (!isset($this->fullWords[$part])) {
                    $this->fullWords[$part] = count($this->fullWords) + 1;
                }

                $wordStemmed = $part;
                $wordFromCache = 0;
                $cachedWord = StemCache::getWord($part);
                if (is_array($cachedWord)) {
                    $wordFromCache = 1;
                    $wordStemmed = $cachedWord['lemma'];
                    if ($cachedWord['no_stem']) {
                        $partStop = 1;
                    } else {
                        $lemmed = 1;
                    }
                }

                if (!$partStop && !$lemmed && $this->morphy !== null && $tryToLemm) {
                    $morphyEncoding = (string)$this->morphy->getEncoding();
                    $isUtf8 = (strcasecmp($morphyEncoding, 'UTF-8') === 0 || strcasecmp($morphyEncoding, 'utf8') === 0);
                    if ($isUtf8) {
                        $wordForMorphy = $part;
                    } else {
                        $enc = $morphyEncoding !== '' ? $morphyEncoding : 'CP1251';
                        $wordForMorphy = mb_convert_encoding($part, $enc, 'UTF-8');
                    }
                    $wordStemmedArray = @$this->morphy->getBaseForm($wordForMorphy);
                    if (is_array($wordStemmedArray) && $wordStemmedArray !== []) {
                        $base = (string)$wordStemmedArray[0];
                        if ($isUtf8) {
                            $wordStemmed = trim($base);
                        } else {
                            $enc = $morphyEncoding !== '' ? $morphyEncoding : 'CP1251';
                            $wordStemmed = trim(mb_convert_encoding($base, 'UTF-8', $enc));
                        }
                        if ($wordStemmed !== '') {
                            $lemmed = 1;
                        } else {
                            $wordStemmed = $part;
                        }
                    }
                }

                if (isset($this->ownNames[$part])) {
                    $wordStemmed = $part;
                }

                if (!$wordFromCache) {
                    StemCache::rememberWord($part, $wordStemmed, $partStop ? 1 : 0);
                }

                $lemmasArray[$i] = $wordStemmed;

                $partLen = mb_strlen($part, 'UTF-8');
                if ($partCount === 1) {
                    // Single surface token: span the whole original space-token (incl. punctuation).
                    $contentStartArray[$i] = $wordPos;
                    $contentLengthArray[$i] = $tokenLen;
                } else {
                    // Map each sub-token to its real char span inside the original token.
                    $partPos = mb_stripos($textData, $part, $searchFrom, 'UTF-8');
                    if ($partPos !== false && $partPos < $tokenEnd) {
                        $contentStartArray[$i] = $partPos;
                        $contentLengthArray[$i] = $partLen;
                        $searchFrom = $partPos + $partLen;
                    } else {
                        $contentStartArray[$i] = min($searchFrom, $tokenEnd);
                        $contentLengthArray[$i] = $partLen;
                        $searchFrom = min($tokenEnd, $searchFrom + $partLen);
                    }
                }
                $i++;
            }
            $lastPos = $tokenEnd;
        }

        StemCache::flushWords();

        return [
            'lemmas' => $lemmasArray,
            'contentStartArray' => $contentStartArray,
            'contentLengthArray' => $contentLengthArray,
        ];
    }
}
