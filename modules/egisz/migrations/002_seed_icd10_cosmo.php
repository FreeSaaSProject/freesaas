<?php
declare(strict_types=1);

/**
 * Copyright (C) 2026 Ilya Kleshnin
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Compact ICD-10 seed for cosmetology / dermatology ambulatory use.
 * Full NSI dictionary can replace/extend later without changing the schema.
 */

return static function (PDO $pdo): void {
    $rows = [
        ['L70.0', 'Acne vulgaris', 'L70'],
        ['L70.1', 'Acne conglobata', 'L70'],
        ['L70.8', 'Other acne', 'L70'],
        ['L70.9', 'Acne, unspecified', 'L70'],
        ['L71.0', 'Perioral dermatitis', 'L71'],
        ['L71.8', 'Other rosacea', 'L71'],
        ['L71.9', 'Rosacea, unspecified', 'L71'],
        ['L72.0', 'Epidermal cyst', 'L72'],
        ['L72.1', 'Trichilemmal cyst', 'L72'],
        ['L72.8', 'Other follicular cysts of skin and subcutaneous tissue', 'L72'],
        ['L73.8', 'Other specified follicular disorders', 'L73'],
        ['L73.9', 'Follicular disorder, unspecified', 'L73'],
        ['L81.0', 'Postinflammatory hyperpigmentation', 'L81'],
        ['L81.1', 'Chloasma', 'L81'],
        ['L81.4', 'Other melanin hyperpigmentation', 'L81'],
        ['L57.0', 'Actinic keratosis', 'L57'],
        ['L57.8', 'Other skin changes due to chronic exposure to nonionizing radiation', 'L57'],
        ['L90.5', 'Scar conditions and fibrosis of skin', 'L90'],
        ['L91.0', 'Hypertrophic scar', 'L91'],
        ['L91.8', 'Other hypertrophic disorders of skin', 'L91'],
        ['L60.0', 'Ingrowing nail', 'L60'],
        ['L60.8', 'Other nail disorders', 'L60'],
        ['L84', 'Corns and callosities', null],
        ['L85.3', 'Xerosis cutis', 'L85'],
        ['L85.8', 'Other specified epidermal thickening', 'L85'],
        ['L98.8', 'Other specified disorders of skin and subcutaneous tissue', 'L98'],
        ['B07', 'Viral warts', null],
        ['B07.9', 'Viral wart, unspecified', 'B07'],
        ['D18.0', 'Haemangioma, any site', 'D18'],
        ['D22.9', 'Melanocytic naevi, unspecified', 'D22'],
        ['D23.9', 'Other benign neoplasm of skin, unspecified', 'D23'],
        ['L82', 'Seborrhoeic keratosis', null],
        ['L21.8', 'Other seborrhoeic dermatitis', 'L21'],
        ['L21.9', 'Seborrhoeic dermatitis, unspecified', 'L21'],
        ['L40.0', 'Psoriasis vulgaris', 'L40'],
        ['L40.9', 'Psoriasis, unspecified', 'L40'],
        ['L20.8', 'Other atopic dermatitis', 'L20'],
        ['L20.9', 'Atopic dermatitis, unspecified', 'L20'],
        ['L23.9', 'Allergic contact dermatitis, unspecified cause', 'L23'],
        ['L30.9', 'Dermatitis, unspecified', 'L30'],
        ['Z01.8', 'Other specified special examinations', 'Z01'],
        ['Z41.1', 'Other plastic surgery for unacceptable cosmetic appearance', 'Z41'],
        ['Z51.8', 'Other specified medical care', 'Z51'],
        ['Z76.8', 'Persons encountering health services in other specified circumstances', 'Z76'],
    ];

    $st = $pdo->prepare(
        'INSERT INTO base_egisz_icd10 (CODE, NAME, PARENT, ENABLED)
         VALUES (?, ?, ?, 1)
         ON DUPLICATE KEY UPDATE NAME = VALUES(NAME), PARENT = VALUES(PARENT), ENABLED = 1'
    );
    foreach ($rows as $row) {
        $st->execute([$row[0], $row[1], $row[2]]);
    }
};
