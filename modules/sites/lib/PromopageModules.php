<?php
declare(strict_types=1);

/**
 * LP MODULES blob editor (oper api_lp.php).
 * Stored as PHP serialize() of moduleCode => field => string.
 */
final class PromopageModules
{
    /**
     * @return list<array{
     *   id:string,
     *   title_key:string,
     *   hint_key?:string,
     *   fields:list<array{code:string,label_key:string,textarea?:bool}>,
     *   grow?:array{prefix:string,break:string,max:int,fields:list<array{code:string,label_key:string,textarea?:bool}>}
     * }>
     */
    public static function catalog(): array
    {
        return [
            [
                'id' => 'BIGSHOW',
                'title_key' => 'sites.promopages.mod.BIGSHOW',
                'hint_key' => 'sites.promopages.mod.hint_grow_blocks',
                'fields' => [
                    ['code' => 'MENUNAME', 'label_key' => 'sites.promopages.mod.field.menuname'],
                ],
                'grow' => [
                    'prefix' => '',
                    'break' => 'URL',
                    'max' => 10,
                    'fields' => [
                        ['code' => 'URL', 'label_key' => 'sites.promopages.mod.field.url_photo'],
                        ['code' => 'TITLE', 'label_key' => 'sites.promopages.mod.field.title_n'],
                        ['code' => 'TITLECOLOR', 'label_key' => 'sites.promopages.mod.field.title_color_n'],
                        ['code' => 'SUBTITLE', 'label_key' => 'sites.promopages.mod.field.subtitle_n'],
                        ['code' => 'SUBTITLECOLOR', 'label_key' => 'sites.promopages.mod.field.subtitle_color_n'],
                        ['code' => 'TEXT', 'label_key' => 'sites.promopages.mod.field.text_n'],
                        ['code' => 'TEXTCOLOR', 'label_key' => 'sites.promopages.mod.field.text_color_n'],
                        ['code' => 'CENTER', 'label_key' => 'sites.promopages.mod.field.center_n'],
                        ['code' => 'REDBUTTONNAME', 'label_key' => 'sites.promopages.mod.field.red_btn_n'],
                        ['code' => 'REDBUTTONURL', 'label_key' => 'sites.promopages.mod.field.red_btn_url_n'],
                        ['code' => 'BLUEBUTTONNAME', 'label_key' => 'sites.promopages.mod.field.blue_btn_n'],
                        ['code' => 'BLUEBUTTONURL', 'label_key' => 'sites.promopages.mod.field.blue_btn_url_n'],
                    ],
                ],
            ],
            [
                'id' => 'BIGSHOW2',
                'title_key' => 'sites.promopages.mod.BIGSHOW2',
                'hint_key' => 'sites.promopages.mod.hint_grow_blocks',
                'fields' => [
                    ['code' => 'MENUNAME', 'label_key' => 'sites.promopages.mod.field.menuname'],
                ],
                'grow' => [
                    'break' => 'URL',
                    'max' => 10,
                    'fields' => [
                        ['code' => 'URL', 'label_key' => 'sites.promopages.mod.field.url_photo'],
                        ['code' => 'TITLE', 'label_key' => 'sites.promopages.mod.field.title_n'],
                        ['code' => 'TEXT', 'label_key' => 'sites.promopages.mod.field.text_n'],
                        ['code' => 'CATEGORY', 'label_key' => 'sites.promopages.mod.field.category_n'],
                        ['code' => 'COLOR', 'label_key' => 'sites.promopages.mod.field.color_n'],
                    ],
                ],
            ],
            [
                'id' => 'WHY4PHOTO',
                'title_key' => 'sites.promopages.mod.WHY4PHOTO',
                'fields' => [
                    ['code' => 'MENUNAME', 'label_key' => 'sites.promopages.mod.field.menuname'],
                    ['code' => 'BGURL', 'label_key' => 'sites.promopages.mod.field.bgurl'],
                    ['code' => 'TITLE', 'label_key' => 'sites.promopages.mod.field.title'],
                    ['code' => 'SUBTITLE', 'label_key' => 'sites.promopages.mod.field.subtitle'],
                    ['code' => 'REDBUTTONNAME', 'label_key' => 'sites.promopages.mod.field.red_btn'],
                    ['code' => 'REDBUTTONURL', 'label_key' => 'sites.promopages.mod.field.red_btn_url'],
                    ['code' => 'BLUEBUTTONNAME', 'label_key' => 'sites.promopages.mod.field.blue_btn'],
                    ['code' => 'BLUEBUTTONURL', 'label_key' => 'sites.promopages.mod.field.blue_btn_url'],
                    ['code' => 'PHOTO1', 'label_key' => 'sites.promopages.mod.field.photo1'],
                    ['code' => 'PHOTO2', 'label_key' => 'sites.promopages.mod.field.photo2'],
                    ['code' => 'PHOTO3', 'label_key' => 'sites.promopages.mod.field.photo3'],
                    ['code' => 'PHOTO4', 'label_key' => 'sites.promopages.mod.field.photo4'],
                    ['code' => 'TEXTAFTER', 'label_key' => 'sites.promopages.mod.field.text_after', 'textarea' => true],
                ],
                'grow' => [
                    'break' => 'POINT',
                    'max' => 10,
                    'fields' => [
                        ['code' => 'POINT', 'label_key' => 'sites.promopages.mod.field.point_n'],
                    ],
                ],
            ],
            [
                'id' => 'TWOBLOCKS',
                'title_key' => 'sites.promopages.mod.TWOBLOCKS',
                'fields' => [
                    ['code' => 'MENUNAME', 'label_key' => 'sites.promopages.mod.field.menuname'],
                    ['code' => 'BGURL', 'label_key' => 'sites.promopages.mod.field.bgurl'],
                    ['code' => 'TITLE1', 'label_key' => 'sites.promopages.mod.field.title1'],
                    ['code' => 'SUBTITLE1', 'label_key' => 'sites.promopages.mod.field.subtitle1'],
                    ['code' => 'TEXT1', 'label_key' => 'sites.promopages.mod.field.text1'],
                    ['code' => 'REDBUTTONNAME1', 'label_key' => 'sites.promopages.mod.field.btn1'],
                    ['code' => 'REDBUTTONURL1', 'label_key' => 'sites.promopages.mod.field.btn1_url'],
                    ['code' => 'PHOTO1', 'label_key' => 'sites.promopages.mod.field.photo1_600'],
                    ['code' => 'BGCOLOR1', 'label_key' => 'sites.promopages.mod.field.bgcolor1'],
                    ['code' => 'TITLE2', 'label_key' => 'sites.promopages.mod.field.title2'],
                    ['code' => 'SUBTITLE2', 'label_key' => 'sites.promopages.mod.field.subtitle2'],
                    ['code' => 'TEXT2', 'label_key' => 'sites.promopages.mod.field.text2'],
                    ['code' => 'REDBUTTONNAME2', 'label_key' => 'sites.promopages.mod.field.btn2'],
                    ['code' => 'REDBUTTONURL2', 'label_key' => 'sites.promopages.mod.field.btn2_url'],
                    ['code' => 'PHOTO2', 'label_key' => 'sites.promopages.mod.field.photo2_600'],
                    ['code' => 'BGCOLOR2', 'label_key' => 'sites.promopages.mod.field.bgcolor2'],
                ],
            ],
            [
                'id' => 'SERVICES',
                'title_key' => 'sites.promopages.mod.SERVICES',
                'hint_key' => 'sites.promopages.mod.hint_services',
                'fields' => [
                    ['code' => 'MENUNAME', 'label_key' => 'sites.promopages.mod.field.menuname'],
                    ['code' => 'TITLE', 'label_key' => 'sites.promopages.mod.field.title'],
                    ['code' => 'TEXT', 'label_key' => 'sites.promopages.mod.field.block_descr'],
                    ['code' => 'BGURL', 'label_key' => 'sites.promopages.mod.field.bgurl'],
                ],
                'grow' => [
                    'break' => 'TITLESERV',
                    'max' => 9,
                    'fields' => [
                        ['code' => 'IMAGE', 'label_key' => 'sites.promopages.mod.field.image_n'],
                        ['code' => 'TITLESERV', 'label_key' => 'sites.promopages.mod.field.service_title_n'],
                        ['code' => 'SHORTDESCR', 'label_key' => 'sites.promopages.mod.field.service_short_n'],
                        ['code' => 'URL', 'label_key' => 'sites.promopages.mod.field.service_url_n'],
                    ],
                ],
            ],
            [
                'id' => 'PRICE',
                'title_key' => 'sites.promopages.mod.PRICE',
                'hint_key' => 'sites.promopages.mod.hint_price',
                'fields' => [
                    ['code' => 'MENUNAME', 'label_key' => 'sites.promopages.mod.field.menuname'],
                    ['code' => 'BGURL', 'label_key' => 'sites.promopages.mod.field.bgurl'],
                    ['code' => 'TITLE', 'label_key' => 'sites.promopages.mod.field.title'],
                    ['code' => 'TEXT', 'label_key' => 'sites.promopages.mod.field.block_descr'],
                    ['code' => 'PICBG', 'label_key' => 'sites.promopages.mod.field.picbg'],
                    ['code' => 'CART', 'label_key' => 'sites.promopages.mod.field.cart'],
                    ['code' => 'LOAD_FIXPRICES', 'label_key' => 'sites.promopages.mod.field.load_fixprices'],
                    ['code' => 'TABLEMODE', 'label_key' => 'sites.promopages.mod.field.tablemode'],
                    ['code' => 'ROW2NUM', 'label_key' => 'sites.promopages.mod.field.row2num'],
                    ['code' => 'ROW1NAME', 'label_key' => 'sites.promopages.mod.field.row1name'],
                    ['code' => 'ROW2NAME', 'label_key' => 'sites.promopages.mod.field.row2name'],
                    ['code' => 'TABLEMODEAFTER', 'label_key' => 'sites.promopages.mod.field.tablemode_after'],
                    ['code' => 'TYPES', 'label_key' => 'sites.promopages.mod.field.price_types'],
                ],
                'grow' => [
                    'break' => 'GOOD_ID',
                    'max' => 19,
                    'fields' => [
                        ['code' => 'GOOD_ID', 'label_key' => 'sites.promopages.mod.field.good_id_n'],
                        ['code' => 'GOOD_NAME', 'label_key' => 'sites.promopages.mod.field.good_name_n'],
                        ['code' => 'GOOD_DESCR', 'label_key' => 'sites.promopages.mod.field.good_descr_n'],
                    ],
                ],
            ],
            [
                'id' => 'PORTFOLIO',
                'title_key' => 'sites.promopages.mod.PORTFOLIO',
                'fields' => [
                    ['code' => 'MENUNAME', 'label_key' => 'sites.promopages.mod.field.menuname'],
                    ['code' => 'BGURL', 'label_key' => 'sites.promopages.mod.field.bgurl'],
                    ['code' => 'TITLE', 'label_key' => 'sites.promopages.mod.field.title'],
                    ['code' => 'TEXT', 'label_key' => 'sites.promopages.mod.field.block_descr'],
                ],
                'grow' => [
                    'break' => 'PIC_URL_',
                    'max' => 40,
                    'fields' => [
                        ['code' => 'PIC_URL_', 'label_key' => 'sites.promopages.mod.field.pic_url_n'],
                        ['code' => 'PIC_TITLE_', 'label_key' => 'sites.promopages.mod.field.pic_title_n'],
                        ['code' => 'PIC_CATEGORY_', 'label_key' => 'sites.promopages.mod.field.pic_cat_n'],
                    ],
                ],
            ],
            [
                'id' => 'TEAM',
                'title_key' => 'sites.promopages.mod.TEAM',
                'fields' => [
                    ['code' => 'MENUNAME', 'label_key' => 'sites.promopages.mod.field.menuname'],
                    ['code' => 'BGURL', 'label_key' => 'sites.promopages.mod.field.bgurl'],
                    ['code' => 'TITLE', 'label_key' => 'sites.promopages.mod.field.title'],
                    ['code' => 'TEXT', 'label_key' => 'sites.promopages.mod.field.block_descr'],
                    ['code' => 'SPECS_IDS', 'label_key' => 'sites.promopages.mod.field.specs_ids'],
                ],
            ],
            [
                'id' => 'TESTIMONIALS',
                'title_key' => 'sites.promopages.mod.TESTIMONIALS',
                'fields' => [
                    ['code' => 'MENUNAME', 'label_key' => 'sites.promopages.mod.field.menuname'],
                    ['code' => 'BGURL', 'label_key' => 'sites.promopages.mod.field.bgurl'],
                    ['code' => 'TITLE', 'label_key' => 'sites.promopages.mod.field.title'],
                    ['code' => 'TEXT', 'label_key' => 'sites.promopages.mod.field.block_descr'],
                    ['code' => 'IDS', 'label_key' => 'sites.promopages.mod.field.testimonial_ids'],
                    ['code' => 'IDS_SPEC', 'label_key' => 'sites.promopages.mod.field.spec_ids'],
                ],
            ],
            [
                'id' => 'BLOG',
                'title_key' => 'sites.promopages.mod.BLOG',
                'fields' => [
                    ['code' => 'MENUNAME', 'label_key' => 'sites.promopages.mod.field.menuname'],
                    ['code' => 'BGURL', 'label_key' => 'sites.promopages.mod.field.bgurl'],
                    ['code' => 'TITLE', 'label_key' => 'sites.promopages.mod.field.title'],
                    ['code' => 'TEXT', 'label_key' => 'sites.promopages.mod.field.block_descr'],
                ],
            ],
            [
                'id' => 'VIDEO',
                'title_key' => 'sites.promopages.mod.VIDEO',
                'fields' => [
                    ['code' => 'MENUNAME', 'label_key' => 'sites.promopages.mod.field.menuname'],
                    ['code' => 'BGURL', 'label_key' => 'sites.promopages.mod.field.bgurl_2000'],
                    ['code' => 'TITLE', 'label_key' => 'sites.promopages.mod.field.title'],
                    ['code' => 'TEXT', 'label_key' => 'sites.promopages.mod.field.descr'],
                    ['code' => 'CODE', 'label_key' => 'sites.promopages.mod.field.youtube'],
                ],
            ],
            [
                'id' => 'FORM1',
                'title_key' => 'sites.promopages.mod.FORM1',
                'fields' => self::formFields(),
            ],
            [
                'id' => 'FORM2',
                'title_key' => 'sites.promopages.mod.FORM2',
                'fields' => self::formFields(),
            ],
            [
                'id' => 'FORM3',
                'title_key' => 'sites.promopages.mod.FORM3',
                'fields' => self::formFields(),
            ],
            [
                'id' => 'WARRANTY',
                'title_key' => 'sites.promopages.mod.WARRANTY',
                'fields' => self::formFields(),
            ],
            [
                'id' => 'TEXT1',
                'title_key' => 'sites.promopages.mod.TEXT1',
                'fields' => [
                    ['code' => 'MENUNAME', 'label_key' => 'sites.promopages.mod.field.menuname'],
                    ['code' => 'BGURL', 'label_key' => 'sites.promopages.mod.field.bgurl_2000'],
                    ['code' => 'TITLE', 'label_key' => 'sites.promopages.mod.field.title_date'],
                    ['code' => 'SUBTITLE', 'label_key' => 'sites.promopages.mod.field.subtitle_date'],
                    ['code' => 'TEXT', 'label_key' => 'sites.promopages.mod.field.descr_date', 'textarea' => true],
                    ['code' => 'H1REQ', 'label_key' => 'sites.promopages.mod.field.h1req'],
                ],
            ],
            [
                'id' => 'HTML',
                'title_key' => 'sites.promopages.mod.HTML',
                'fields' => [
                    ['code' => 'MENUNAME', 'label_key' => 'sites.promopages.mod.field.menuname'],
                    ['code' => 'TEXT', 'label_key' => 'sites.promopages.mod.field.html', 'textarea' => true],
                ],
            ],
            [
                'id' => 'TEXTSIMPLE1',
                'title_key' => 'sites.promopages.mod.TEXTSIMPLE1',
                'fields' => [
                    ['code' => 'MENUNAME', 'label_key' => 'sites.promopages.mod.field.menuname'],
                    ['code' => 'BGURL', 'label_key' => 'sites.promopages.mod.field.bgurl_2000'],
                    ['code' => 'TITLE', 'label_key' => 'sites.promopages.mod.field.title_date'],
                    ['code' => 'SUBTITLE', 'label_key' => 'sites.promopages.mod.field.subtitle_date'],
                    ['code' => 'TEXT', 'label_key' => 'sites.promopages.mod.field.descr_date', 'textarea' => true],
                    ['code' => 'H1REQ', 'label_key' => 'sites.promopages.mod.field.h1req'],
                ],
            ],
            [
                'id' => 'PROMO',
                'title_key' => 'sites.promopages.mod.PROMO',
                'fields' => [
                    ['code' => 'MENUNAME', 'label_key' => 'sites.promopages.mod.field.menuname'],
                ],
            ],
            [
                'id' => 'SPECPRICE',
                'title_key' => 'sites.promopages.mod.SPECPRICE',
                'fields' => [
                    ['code' => 'MENUNAME', 'label_key' => 'sites.promopages.mod.field.menuname'],
                    ['code' => 'BGURL', 'label_key' => 'sites.promopages.mod.field.bgurl_2000'],
                    ['code' => 'TITLE', 'label_key' => 'sites.promopages.mod.field.title_date'],
                    ['code' => 'SUBTITLE', 'label_key' => 'sites.promopages.mod.field.subtitle_date'],
                    ['code' => 'TEXT', 'label_key' => 'sites.promopages.mod.field.descr_date', 'textarea' => true],
                    ['code' => 'H1REQ', 'label_key' => 'sites.promopages.mod.field.h1req'],
                    ['code' => 'PRODUCTS', 'label_key' => 'sites.promopages.mod.field.products_mode'],
                    ['code' => 'underText1', 'label_key' => 'sites.promopages.mod.field.under1'],
                    ['code' => 'underText2', 'label_key' => 'sites.promopages.mod.field.under2'],
                    ['code' => 'underText3', 'label_key' => 'sites.promopages.mod.field.under3'],
                ],
            ],
            [
                'id' => 'GALLERY',
                'title_key' => 'sites.promopages.mod.GALLERY',
                'fields' => [
                    ['code' => 'MENUNAME', 'label_key' => 'sites.promopages.mod.field.menuname'],
                    ['code' => 'TITLE', 'label_key' => 'sites.promopages.mod.field.title_date'],
                    ['code' => 'SUBTITLE', 'label_key' => 'sites.promopages.mod.field.subtitle_date'],
                ],
                'grow' => [
                    'break' => 'IMAGE',
                    'max' => 9,
                    'fields' => [
                        ['code' => 'IMAGE', 'label_key' => 'sites.promopages.mod.field.image_n_700'],
                        ['code' => 'TITLESERV', 'label_key' => 'sites.promopages.mod.field.service_title_n'],
                        ['code' => 'URL', 'label_key' => 'sites.promopages.mod.field.btn_url_n'],
                        ['code' => 'URLNAME', 'label_key' => 'sites.promopages.mod.field.btn_text_n'],
                    ],
                ],
            ],
            [
                'id' => 'ACTIONS',
                'title_key' => 'sites.promopages.mod.ACTIONS',
                'fields' => [
                    ['code' => 'MENUNAME', 'label_key' => 'sites.promopages.mod.field.menuname'],
                    ['code' => 'TITLE', 'label_key' => 'sites.promopages.mod.field.title_date'],
                    ['code' => 'SUBTITLE', 'label_key' => 'sites.promopages.mod.field.subtitle_date'],
                    ['code' => 'TEXT', 'label_key' => 'sites.promopages.mod.field.descr'],
                ],
                'grow' => [
                    'break' => 'IMAGE',
                    'max' => 9,
                    'fields' => [
                        ['code' => 'IMAGE', 'label_key' => 'sites.promopages.mod.field.image_n_700'],
                        ['code' => 'TITLESERV', 'label_key' => 'sites.promopages.mod.field.service_title_n'],
                        ['code' => 'URL', 'label_key' => 'sites.promopages.mod.field.btn_url_n'],
                        ['code' => 'URLNAME', 'label_key' => 'sites.promopages.mod.field.btn_text_n'],
                        ['code' => 'SHORTDESCR', 'label_key' => 'sites.promopages.mod.field.short_n'],
                        ['code' => 'ICONNAME', 'label_key' => 'sites.promopages.mod.field.icon_n'],
                        ['code' => 'GOODID', 'label_key' => 'sites.promopages.mod.field.goodid_n'],
                    ],
                ],
            ],
        ];
    }

    /** @return list<array{code:string,label_key:string,textarea?:bool}> */
    private static function formFields(): array
    {
        return [
            ['code' => 'MENUNAME', 'label_key' => 'sites.promopages.mod.field.menuname'],
            ['code' => 'BGURL', 'label_key' => 'sites.promopages.mod.field.bgurl_2000'],
            ['code' => 'TITLE', 'label_key' => 'sites.promopages.mod.field.title_date'],
            ['code' => 'TEXT', 'label_key' => 'sites.promopages.mod.field.descr_date'],
            ['code' => 'BUTTONNAME', 'label_key' => 'sites.promopages.mod.field.button_name'],
        ];
    }

    /** @return array<string,array<string,string>> */
    public static function decode(string $raw): array
    {
        $raw = trim($raw);
        if ($raw === '') {
            return [];
        }
        $data = self::unserializeLoose($raw);
        if (!is_array($data)) {
            return [];
        }
        $out = [];
        foreach ($data as $modId => $fields) {
            if (!is_string($modId) || !is_array($fields)) {
                continue;
            }
            $row = [];
            foreach ($fields as $k => $v) {
                if (!is_string($k) && !is_int($k)) {
                    continue;
                }
                $row[(string)$k] = is_scalar($v) ? (string)$v : '';
            }
            $out[$modId] = $row;
        }
        return $out;
    }

    /**
     * PHP serialize with optional length-fix (legacy UTF-8 / broken s:N payloads).
     *
     * @return mixed
     */
    private static function unserializeLoose(string $raw)
    {
        $data = @unserialize($raw, ['allowed_classes' => false]);
        if (is_array($data) || $raw === 'b:0;') {
            return $data;
        }
        $fixed = (string)preg_replace_callback(
            '/s:(\d+):"(.*?)"(?=;|})/s',
            static function (array $m): string {
                $actual = strlen($m[2]);
                if ((int)$m[1] === $actual) {
                    return $m[0];
                }
                return 's:' . $actual . ':"' . $m[2] . '"';
            },
            $raw
        );
        $data = @unserialize($fixed, ['allowed_classes' => false]);
        return is_array($data) ? $data : false;
    }

    /** @param array<string,array<string,string>> $data */
    public static function encode(array $data): string
    {
        $clean = [];
        foreach ($data as $modId => $fields) {
            if (!is_string($modId) || !is_array($fields)) {
                continue;
            }
            $row = [];
            foreach ($fields as $k => $v) {
                $row[(string)$k] = is_scalar($v) ? (string)$v : '';
            }
            $clean[$modId] = $row;
        }
        return serialize($clean);
    }

    /**
     * Apply LOAD_FIXPRICES side-effect from GOOD_PRICES blob (oper).
     *
     * @param array<string,array<string,string>> $data
     * @return array<string,array<string,string>>
     */
    public static function applyFixPrices(array $data, string $goodPricesRaw): array
    {
        $load = (int)($data['PRICE']['LOAD_FIXPRICES'] ?? 0);
        if ($load < 1 || $goodPricesRaw === '') {
            return $data;
        }
        $decoded = @unserialize(base64_decode($goodPricesRaw, true) ?: '', ['allowed_classes' => false]);
        if (!is_array($decoded)) {
            $decoded = @unserialize($goodPricesRaw, ['allowed_classes' => false]);
        }
        if (!is_array($decoded)) {
            return $data;
        }
        if (!isset($data['PRICE']) || !is_array($data['PRICE'])) {
            $data['PRICE'] = [];
        }
        $i = 1;
        foreach ($decoded as $gid => $_gidData) {
            $data['PRICE']['GOOD_ID' . $i] = (string)$gid;
            $i++;
            if ($i > 19) {
                break;
            }
        }
        return $data;
    }

    /**
     * @param array<string,mixed> $postModules modules[MOD][FIELD]
     * @return array<string,array<string,string>>
     */
    public static function fromPost(array $postModules): array
    {
        $out = [];
        foreach (self::catalog() as $mod) {
            $id = $mod['id'];
            $src = is_array($postModules[$id] ?? null) ? $postModules[$id] : [];
            $row = [];
            foreach ($src as $k => $v) {
                if (!is_string($k) && !is_int($k)) {
                    continue;
                }
                // Keep quotes/apostrophes — HTML and copy need them; encode() serializes safely.
                $row[(string)$k] = is_scalar($v) ? (string)$v : '';
            }
            if (!isset($row['NUMBER'])) {
                $row['NUMBER'] = '';
            }
            if ((int)$row['NUMBER'] < 1) {
                $out[$id] = ['NUMBER' => $row['NUMBER'] === '' ? '' : '0'];
                continue;
            }
            $out[$id] = $row;
        }
        return $out;
    }

    /**
     * Fields shown when module NUMBER > 0 (including one empty grow slot).
     *
     * @param array<string,mixed> $mod
     * @param array<string,string> $modData
     * @return list<array{code:string,label:string,textarea?:bool}>
     */
    public static function visibleFields(array $mod, array $modData): array
    {
        $out = [];
        foreach ($mod['fields'] as $f) {
            $out[] = [
                'code' => $f['code'],
                'label' => t($f['label_key']),
                'textarea' => !empty($f['textarea']),
            ];
        }
        $grow = $mod['grow'] ?? null;
        if (!is_array($grow)) {
            return $out;
        }
        $break = (string)$grow['break'];
        $max = (int)$grow['max'];
        for ($i = 1; $i <= $max; $i++) {
            foreach ($grow['fields'] as $f) {
                $code = (string)$f['code'] . $i;
                $out[] = [
                    'code' => $code,
                    'label' => t($f['label_key'], ['n' => $i]),
                    'textarea' => !empty($f['textarea']),
                ];
            }
            $breakCode = $break . $i;
            if (trim((string)($modData[$breakCode] ?? '')) === '') {
                break;
            }
        }
        return $out;
    }

    public static function isActive(array $modData): bool
    {
        return (int)($modData['NUMBER'] ?? 0) > 0;
    }
}
