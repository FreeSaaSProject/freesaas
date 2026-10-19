<?php
declare(strict_types=1);

/**
 * elos/oper document templates → FreeSaaS: placeholder mapping, gender forms and
 * enterprise-data scrubbing. Detection is pattern-based (legal form + quotes, ИНН/КПП/ОГРН,
 * licence, registrar, addresses, phones, own site, director) so no company literals live in code.
 */
final class DocsLegacy
{
    /** Legacy printdoc.php variables → engine placeholders. */
    public const PLACEHOLDERS = [
        '{fullUserName}' => '{client.name}',
        '{userTel}' => '{client.tel}',
        '{userMail}' => '{client.mail}',
        '{birthday}' => '{client.birthday}',
        '{addressFact}' => '{client.address_fact}',
        '{passSerie}' => '{client.passport_serie}',
        '{passNum}' => '{client.passport_number}',
        '{passDate}' => '{client.passport_issued_at}',
        '{passPlace}' => '{client.passport_issued_by}',
        '{passAddr}' => '{client.address_passport}',
        '{documentDate}' => '{doc.date}',
        '{documentDateExpire}' => '{doc.expire}',
        '{eventID}' => '{event.id}',
        '{actNum}' => '{doc.id}',
        '{actDogNum}' => '{contract.id}',
        '{actDogDate}' => '{contract.date}',
        '{prodTable}' => '{event.items}',
        '{total_to_pay}' => '{event.total}',
        '{total_to_pay_text}' => '{event.total_text}',
        '{proceduresList}' => '{event.procedures}',
        '{uved_nabor_usl}' => '{event.service_types}',
        '{uved_doctor}' => '{doctor.name}',
        '{doc_sta_name}' => '{doctor.position}',
        '{uved_recs}' => '{visit.recommends}',
        '{cosmetologist}' => '{event.staff}',
        'QRID' => '{qr}',
    ];

    /** Entities that matter for matching names/quotes; &lt;/&gt;/&amp; are left alone. */
    private const ENTITIES = [
        '&nbsp;' => "\u{00a0}", '&#160;' => "\u{00a0}",
        '&laquo;' => '«', '&raquo;' => '»', '&#171;' => '«', '&#187;' => '»',
        '&ldquo;' => '“', '&rdquo;' => '”', '&bdquo;' => '„', '&quot;' => '"',
        '&mdash;' => '—', '&ndash;' => '–', '&#8212;' => '—', '&#8211;' => '–',
    ];

    /** Gap allowed between words of a literal inside HTML (spaces, nbsp, inline tags). */
    private const GAP = '(?:[\s\x{00a0}]|&nbsp;|<[^>]{0,200}>)+';

    public static function normalizeHtml(string $html): string
    {
        return strtr($html, self::ENTITIES);
    }

    /** Plain text for detection (tags → space, entities decoded, spaces collapsed). */
    public static function plainText(string $html): string
    {
        $t = preg_replace('/<[^>]*>/', ' ', self::normalizeHtml($html)) ?? '';
        $t = html_entity_decode($t, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return trim(preg_replace('/[\s\x{00a0}]+/u', ' ', $t) ?? '');
    }

    /** Placeholders + legacy gender syntax [f|m|x] → {g:f|m|x}. */
    public static function convertPlaceholders(string $html): string
    {
        $html = strtr($html, self::PLACEHOLDERS);
        return (string)preg_replace(
            '/\[([^\[\]|<>{}\n]{0,40})\|([^\[\]|<>{}\n]{0,40})(?:\|([^\[\]|<>{}\n]{0,40}))?\]/u',
            '{g:$1|$2|$3}',
            $html
        );
    }

    /**
     * Find the company data repeated across templates.
     *
     * @param list<string> $texts plainText() of every template
     * @return array{map:array<string,string>,needles:list<string>,found:array<string,list<string>>}
     *   map = literal → replacement (longest first), needles = residual check words, found = per placeholder
     */
    public static function detectEnterprise(array $texts): array
    {
        $all = implode("\n", $texts);
        $map = [];
        $needles = [];
        $found = [];
        $add = static function (string $literal, string $replacement, string $key) use (&$map, &$found): void {
            $literal = trim($literal);
            if ($literal === '' || isset($map[$literal])) {
                return;
            }
            $map[$literal] = $replacement;
            $found[$key][] = $literal;
        };

        // Legal name: most frequent «…» after a legal form is the operator; its initials = short name.
        // Legal form may be typed with Latin O ("OOO").
        preg_match_all('/([ОO]{3}|[ОO]АО|ЗАО|ПАО|АО|ИП)\s*[«"“„]\s*([^»"”“«]{2,80}?)\s*[»"”“]/u', $all, $m, PREG_SET_ORDER);
        $byName = [];
        foreach ($m as $hit) {
            $norm = mb_strtolower(preg_replace('/\s+/u', ' ', trim($hit[2])) ?? '');
            $byName[$norm][] = $hit[0];
        }
        uasort($byName, static fn(array $a, array $b): int => count($b) <=> count($a));
        $mainName = (string)array_key_first($byName);
        $initials = '';
        foreach (preg_split('/[\s\-]+/u', $mainName) ?: [] as $w) {
            if ($w !== '') {
                $initials .= mb_strtoupper(mb_substr($w, 0, 1));
            }
        }
        foreach ($byName as $norm => $literals) {
            if ($norm === $mainName) {
                foreach ($literals as $lit) {
                    $add($lit, '{org.name}', 'org.name');
                }
            } elseif (mb_strlen($initials) >= 2 && mb_strtoupper(str_replace(' ', '', $norm)) === $initials) {
                foreach ($literals as $lit) {
                    $add($lit, '{org.short_name}', 'org.short_name');
                }
            }
        }
        if ($mainName !== '') {
            // Same name without quotes ("ООО Клуб …") and the bare brand anywhere else.
            $nameRe = implode('\s+', array_map(
                static fn(string $w): string => preg_quote($w, '/'),
                preg_split('/\s+/u', $mainName) ?: []
            ));
            preg_match_all('/(?:[ОO]{3}|[ОO]АО|ЗАО|ПАО|АО|ИП)\s+' . $nameRe . '/iu', $all, $um);
            foreach (array_unique($um[0]) as $lit) {
                $add($lit, '{org.name}', 'org.name');
            }
            preg_match_all('/' . $nameRe . '/iu', $all, $bm);
            foreach (array_unique($bm[0]) as $lit) {
                $add($lit, '{org.brand}', 'org.brand');
            }
            $needles[] = $mainName;
            if (mb_strlen($initials) >= 2) {
                $needles[] = '«' . $initials . '»';
                $needles[] = '"' . $initials . '"';
            }
        }

        // Registration numbers (labelled first; then the bare value wherever it repeats).
        $numbers = [
            'org.inn' => '/ИНН\D{0,5}(\d{10,12})/u',
            'org.kpp' => '/КПП\D{0,5}(\d{9})/u',
            'org.ogrn' => '/(?:ОГРН|государственной\s+регистрации)\D{0,8}(\d{13,15})/u',
            'org.account' => '/(?:р\/с|расч[её]тный\s+сч[её]т)\D{0,5}(\d{20})/u',
            'org.corr_account' => '/(?:к\/с|корр?\.?\s*сч[её]т)\D{0,5}(\d{20})/u',
            'org.bik' => '/БИК\D{0,5}(\d{9})/u',
        ];
        foreach ($numbers as $key => $re) {
            preg_match_all($re, $all, $mm);
            foreach (array_unique($mm[1]) as $num) {
                if ($key === 'org.ogrn' && preg_match_all('/' . $num . '\s*от\s*(\d{2}\.\d{2}\.\d{4})/u', $all, $dm)) {
                    foreach (array_unique($dm[0]) as $composite) {
                        $add($composite, '{org.ogrn} от {org.ogrn_date}', 'org.ogrn_date');
                    }
                }
                $add($num, '{' . $key . '}', $key);
                $needles[] = $num;
            }
        }

        // Medical licence number + date, issuer and issuer address, tax registrar.
        preg_match_all('/№\s*(Л[ОO0]?[\-0-9А-ЯA-Z\/]{4,})\s+от\s+(\d{2}\.\d{2}\.\d{4})/u', $all, $lm, PREG_SET_ORDER);
        foreach ($lm as $hit) {
            $add($hit[0], '№{org.license} от {org.license_date}', 'org.license');
            $needles[] = $hit[1];
        }
        preg_match_all('/выданн?\w*\s+(Министерств\w*\s+здравоохранения\s+[^,;.]{3,60}?)(?=\s*[,;.]|\s+расположен|\s+а\s)/u', $all, $im);
        foreach (array_unique($im[1]) as $issuer) {
            $add($issuer, '{org.license_issuer}', 'org.license_issuer');
        }
        preg_match_all('/расположенн?\w*\s+по\s+адресу:\s*([^;]{5,120});/u', $all, $am);
        $issuerAddresses = [];
        foreach (array_unique($am[0]) as $i => $composite) {
            $issuerAddresses[] = trim($am[1][$i] ?? '');
            $add(rtrim($composite, ';'), 'расположенному по адресу: {org.license_issuer_address}', 'org.license_issuer_address');
        }
        // Licensed activities list (licence appendix) differs per clinic.
        preg_match_all('/приложением\s+к\s+лицензии:\s*([^.]{20,2000})\./u', $all, $scm);
        foreach (array_unique($scm[1]) as $scope) {
            $add($scope, '{org.license_scope}', 'org.license_scope');
        }
        preg_match_all('/Зарегистрированн?\w*\s+(ИФНС[^,;]{3,80})/u', $all, $rm);
        foreach (array_unique($rm[1]) as $reg) {
            $add($reg, '{org.registrar}', 'org.registrar');
        }

        // Addresses (index first, or city first) → place of service.
        $street = '(?:(?:ул\.|улица|пер\.|переулок|пр-т|проспект|пр\.|б-р|бульвар|ш\.|шоссе|наб\.)\s*[А-ЯЁ][а-яё\-]+|[А-ЯЁ][а-яё\-]+\s+(?:пер\.|ул\.|пр-т|б-р))';
        $tail = '(?:\s*,\s*(?:\d+\s*этаж|ком\.\s*\d[\d\-]*(?:\s*,\s*\d[\d\-]*)*|оф\.\s*\d+|пом\.\s*[\dIVX]+))?';
        $addrRes = [
            '/\d{6},?\s*(?:[А-ЯЁ][а-яё]+\s+область,\s*)?(?:(?:г\.|город)\s*[А-ЯЁ][а-яё\-]+,?\s*|[А-ЯЁ][а-яё\-]+,\s*)?' . $street
                . '\s*,?\s*(?:индекс\s*\d{6},?\s*)?(?:д\.|дом)\s*\d+[А-Яа-яA-Za-z]?' . $tail . '/u',
            '/(?:г\.|город)\s*[А-ЯЁ][а-яё\-]+,\s*' . $street . ',\s*(?:индекс\s*\d{6},\s*)?(?:д\.|дом)\s*\d+[А-Яа-я]?' . $tail . '/u',
        ];
        $cities = [];
        foreach ($addrRes as $re) {
            preg_match_all($re, $all, $xm);
            foreach (array_unique($xm[0]) as $addr) {
                $addr = rtrim(trim($addr), ',.');
                if (in_array($addr, $issuerAddresses, true)) {
                    continue;
                }
                $add($addr, '{org.address}', 'org.address');
                if (preg_match('/(?:г\.|город)\s*([А-ЯЁ][а-яё\-]+)/u', $addr, $cm)) {
                    $cities[] = $cm[1];
                }
                if (preg_match('/' . $street . '/u', $addr, $sm)) {
                    $needles[] = trim($sm[0]);
                }
            }
        }

        // Phones.
        preg_match_all('/(?:\+7|8)\s?\(\d{3,4}\)\s?\d[\d\- ]{4,8}\d|\(\d{3,4}\)\s?\d{2,3}-\d{2}-\d{2}/u', $all, $pm);
        foreach (array_unique($pm[0]) as $phone) {
            $add($phone, '{org.phone}', 'org.phone');
            $needles[] = substr(preg_replace('/\D+/', '', $phone) ?? '', -7);
        }

        // Own site = most frequent linked domain.
        preg_match_all('/https?:\/\/(?:www\.)?([a-z0-9\-]+(?:\.[a-z0-9\-]+)*\.[a-z]{2,})\/?/iu', $all, $um, PREG_SET_ORDER);
        $domains = [];
        foreach ($um as $hit) {
            $domains[strtolower($hit[1])][] = $hit[0];
        }
        uasort($domains, static fn(array $a, array $b): int => count($b) <=> count($a));
        $own = (string)array_key_first($domains);
        if ($own !== '' && count($domains[$own]) >= 2) {
            foreach (array_unique($domains[$own]) as $url) {
                $add($url, '{org.site}', 'org.site');
            }
            $add($own, '{org.domain}', 'org.domain');
            $needles[] = $own;
        }

        // Director: genitive full name after "в лице … директора".
        preg_match_all('/в\s+лице\s+(?:генерального\s+)?директора\s+([А-ЯЁ][а-яё]+)\s+([А-ЯЁ][а-яё]+)\s+([А-ЯЁ][а-яё]+)/u', $all, $dm, PREG_SET_ORDER);
        foreach ($dm as $hit) {
            $add($hit[1] . ' ' . $hit[2] . ' ' . $hit[3], '{org.director_genitive}', 'org.director_genitive');
            $needles[] = (string)preg_replace('/(ой|ого|а|ы|и)$/u', '', $hit[1]);
        }
        // Signatory short forms "Фамилия И.О." repeated across templates (doctors are {doctor.name} already):
        // shortest surname form = nominative, longer forms of the same stem = genitive.
        preg_match_all('/([А-ЯЁ][а-яё]{2,})\s*([А-ЯЁ])\.\s*([А-ЯЁ])\./u', $all, $sm, PREG_SET_ORDER);
        $people = [];
        foreach ($sm as $hit) {
            $stem = (string)preg_replace('/(ой|ого|а|ы|и)$/u', '', $hit[1]);
            $people[$stem . '|' . $hit[2] . $hit[3]][] = $hit;
        }
        foreach ($people as $key => $hits) {
            if (count($hits) < 2) {
                continue;
            }
            $forms = array_unique(array_map(static fn(array $h): string => $h[1], $hits));
            usort($forms, static fn(string $a, string $b): int => mb_strlen($a) <=> mb_strlen($b));
            $nominative = $forms[0];
            foreach ($hits as $hit) {
                $add($hit[0], $hit[1] === $nominative ? '{org.director_short}' : '{org.director_short_genitive}', 'org.director_short');
            }
            $needles[] = explode('|', $key)[0];
        }

        // City / region (any case form) from the detected addresses.
        $cityCounts = array_count_values($cities);
        arsort($cityCounts);
        $city = (string)array_key_first($cityCounts);
        if ($city !== '') {
            $cityStem = mb_substr($city, 0, max(3, mb_strlen($city) - 1));
            $needles[] = $cityStem;
            preg_match_all('/(?:г\.\s*|город\s+)' . preg_quote($cityStem, '/') . '[а-яё]*/u', $all, $ym);
            foreach (array_unique($ym[0]) as $lit) {
                $add($lit, (mb_strpos($lit, 'город') === 0 ? 'город ' : 'г. ') . '{org.city}', 'org.city');
            }
            preg_match_all('/' . preg_quote($cityStem, '/') . '[а-яё]+\s+[Оо]бласт[а-яё]*/u', $all, $gm);
            foreach (array_unique($gm[0]) as $lit) {
                // "Самарская область" vs "в Самарской области" — keep the grammatical case.
                $nominative = (bool)preg_match('/(ая|ий)\s+[Оо]бласть$/u', $lit);
                $add($lit, $nominative ? '{org.region}' : '{org.region_genitive}', 'org.region');
            }
        }

        uksort($map, static fn(string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));
        $needles = array_values(array_unique(array_filter($needles, static fn(string $s): bool => mb_strlen($s) >= 3)));
        return ['map' => $map, 'needles' => $needles, 'found' => $found];
    }

    /** Apply literal → placeholder map to template HTML (tolerates nbsp/inline tags between words). */
    public static function scrub(string $html, array $map): string
    {
        $html = self::normalizeHtml($html);
        foreach ($map as $literal => $replacement) {
            $parts = preg_split('/\s+/u', (string)$literal) ?: [];
            $re = implode(self::GAP, array_map(static fn(string $p): string => preg_quote($p, '/'), $parts));
            $html = (string)preg_replace('/' . $re . '/u', str_replace(['\\', '$'], ['\\\\', '\\$'], (string)$replacement), $html);
        }
        return $html;
    }

    /**
     * Images on the legacy host (relative src) do not travel: signature facsimiles → {org.signature},
     * other local images are dropped. External diagrams (absolute http/https) stay.
     *
     * @param int $dropped incremented per removed image
     */
    public static function scrubImages(string $html, int &$dropped = 0): string
    {
        return (string)preg_replace_callback(
            '/<img\b[^>]*?\bsrc\s*=\s*["\']?([^"\'\s>]+)[^>]*>/iu',
            static function (array $m) use (&$dropped): string {
                if (preg_match('#^(https?:)?//#i', $m[1])) {
                    return $m[0];
                }
                if (preg_match('/sign|podp|faks|facsim/i', basename($m[1]))) {
                    return '{org.signature}';
                }
                $dropped++;
                return '';
            },
            $html
        );
    }

    /**
     * Residual company traces (case-insensitive) with short context.
     *
     * @param list<string> $needles
     * @return list<string>
     */
    public static function residual(string $html, array $needles): array
    {
        // Plain text for context; raw HTML too so attributes (img src, href) are covered.
        $text = self::plainText($html);
        $raw = self::normalizeHtml($html);
        $out = [];
        foreach ($needles as $n) {
            foreach ([$text, $raw] as $hay) {
                $pos = mb_stripos($hay, $n);
                if ($pos !== false) {
                    $out[] = $n . ' … ' . mb_substr($hay, max(0, $pos - 40), 100);
                    break;
                }
            }
        }
        return $out;
    }

    public static function kindForCode(string $code): string
    {
        if (preg_match('/^Д[\-–]/u', $code)) {
            return 'contract';
        }
        if (preg_match('/^А[\-–]/u', $code)) {
            return 'check';
        }
        return 'consent';
    }

    /**
     * EVERY: 1 medical, 2 non-medical, 3 every visit; PROCS / TYPES are DB-specific ids.
     *
     * @param array<string,mixed> $row legacy base_docs row
     * @return array{mode:string,types:list<int>,procs:list<int>}
     */
    public static function trigger(array $row): array
    {
        $ids = static function ($raw): array {
            $out = [];
            foreach (preg_split('/[#,;\s.]+/', (string)$raw) ?: [] as $p) {
                if ((int)$p > 0) {
                    $out[] = (int)$p;
                }
            }
            return array_values(array_unique($out));
        };
        $procs = $ids($row['PROCS'] ?? '');
        $types = $ids($row['TYPES'] ?? '');
        $mode = 'manual';
        if ($procs !== []) {
            $mode = 'procs';
        } elseif ($types !== []) {
            $mode = 'types';
        } else {
            $mode = ['1' => 'medical', '2' => 'non_medical', '3' => 'every'][(string)($row['EVERY'] ?? '')] ?? 'manual';
        }
        return ['mode' => $mode, 'types' => $types, 'procs' => $procs];
    }

    /** Latin code slug for file names / definition codes. */
    public static function slug(string $s): string
    {
        static $tr = [
            'а' => 'a', 'б' => 'b', 'в' => 'v', 'г' => 'g', 'д' => 'd', 'е' => 'e', 'ё' => 'e', 'ж' => 'zh', 'з' => 'z',
            'и' => 'i', 'й' => 'y', 'к' => 'k', 'л' => 'l', 'м' => 'm', 'н' => 'n', 'о' => 'o', 'п' => 'p', 'р' => 'r',
            'с' => 's', 'т' => 't', 'у' => 'u', 'ф' => 'f', 'х' => 'h', 'ц' => 'c', 'ч' => 'ch', 'ш' => 'sh', 'щ' => 'sch',
            'ъ' => '', 'ы' => 'y', 'ь' => '', 'э' => 'e', 'ю' => 'yu', 'я' => 'ya',
        ];
        $s = strtr(mb_strtolower($s), $tr);
        $s = trim((string)preg_replace('/[^a-z0-9]+/', '_', $s), '_');
        return $s !== '' ? substr($s, 0, 56) : 'doc';
    }
}
