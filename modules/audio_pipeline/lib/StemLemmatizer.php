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
            $lemmed = 0;
            $stopLemmStem = 0;

            $isStop = mb_substr($word, 0, 1, 'UTF-8');
            if ($isStop === '#') {
                $stopLemmStem = 1;
            }
            $word = str_replace('#', '', $word);
            $origWordLen = mb_strlen($word, 'UTF-8');

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
            if ($word === '') {
                continue;
            }

            if ($stopLemmStem) {
                if (!isset($this->noStemWords[$word])) {
                    $this->noStemWords[$word] = 1;
                }
            }
            if (isset($this->noStemWords[$word])) {
                $stopLemmStem = 1;
            }

            if (isset($this->badLemWords[$word])) {
                $word = $this->badLemWords[$word];
            }
            if (!isset($this->fullWords[$word])) {
                $this->fullWords[$word] = count($this->fullWords) + 1;
            }

            $wordStemmed = $word;
            $wordFromCache = 0;
            $cachedWord = StemCache::getWord($word);
            if (is_array($cachedWord)) {
                $wordFromCache = 1;
                $wordStemmed = $cachedWord['lemma'];
                if ($cachedWord['no_stem']) {
                    $stopLemmStem = 1;
                } else {
                    $lemmed = 1;
                }
            }

            if (!$stopLemmStem && !$lemmed && $this->morphy !== null && $tryToLemm) {
                $morphyEncoding = (string)$this->morphy->getEncoding();
                $isUtf8 = (strcasecmp($morphyEncoding, 'UTF-8') === 0 || strcasecmp($morphyEncoding, 'utf8') === 0);
                // Bundled oper dicts report utf-8 — pass words as UTF-8 (do not convert to CP1251).
                if ($isUtf8) {
                    $wordForMorphy = $word;
                } else {
                    $enc = $morphyEncoding !== '' ? $morphyEncoding : 'CP1251';
                    $wordForMorphy = mb_convert_encoding($word, $enc, 'UTF-8');
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
                        $wordStemmed = $word;
                    }
                }
                // Morph miss: keep surface form ($wordStemmed already = $word).
            }

            if (isset($this->ownNames[$word])) {
                $wordStemmed = $word;
            }

            if (!$wordFromCache) {
                StemCache::rememberWord($word, $wordStemmed, $stopLemmStem ? 1 : 0);
            }

            $lemmasArray[$i] = $wordStemmed;

            $wordPos = mb_stripos($textData, $wordOriginalForSearch, $lastPos, 'UTF-8');
            if ($wordPos !== false) {
                $contentStartArray[$i] = $wordPos;
                $wordLenInText = mb_strlen($wordOriginalForSearch, 'UTF-8');
                $lastPos = $wordPos + $wordLenInText;
                $contentLengthArray[$i] = $wordLenInText;
            } else {
                $contentStartArray[$i] = $symNumber;
                $contentLengthArray[$i] = $origWordLen;
                $symNumber += $origWordLen + 1;
                $lastPos = $symNumber;
            }
            $i++;
        }

        StemCache::flushWords();

        return [
            'lemmas' => $lemmasArray,
            'contentStartArray' => $contentStartArray,
            'contentLengthArray' => $contentLengthArray,
        ];
    }
}
