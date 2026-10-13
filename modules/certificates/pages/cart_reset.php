<?php
declare(strict_types=1);

CertificatesCart::clear();
flash_set('ok', t('certificates.cart.cleared'));
redirect('/cart');
