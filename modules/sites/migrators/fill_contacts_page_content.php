<?php
declare(strict_types=1);

/**
 * Legacy likespa.hm kept contacts HTML in pages/contacts.php (DB content empty).
 * Fill base_page_categories for link=contactsart / linkRW=/contacts/ when empty.
 *
 * @return array{updated:int,skipped:int}
 */
return static function (): array {
    if (!Database::tableExists('base_page_categories')) {
        return ['updated' => 0, 'skipped' => 1];
    }

    $html = <<<'HTML'
<div itemscope itemtype="http://schema.org/LocalBusiness">
<h2 itemprop="name">LikeSpa</h2>
<p><i>Отдых, который нравится</i></p>

<p>Связаться с нами можно любым удобным способом:</p>
<ul class="list-check primary">
<li>Позвоните нам по телефону: <a href="tel:+79063458972" itemprop="telephone">+79063458972</a></li>
<li>Напишите письмо по адресу электронной почты: <a href="mailto:in@likespa.ru" itemprop="email">in@likespa.ru</a></li>
<li>Телефон руководителя: <a href="tel:+79277227504" itemprop="telephone">+79277227504</a> Клешнин Илья Валерьевич</li>
<li>Telegram-бот: запись и отмена процедур, бонусный счёт, опросы о качестве — <a href="https://t.me/likespabot" target="_blank" rel="noreferrer">@likespabot</a></li>
</ul>

<p>Режим работы: <span itemprop="openingHours" content="Mo-Su 10:00-22:00">Ежедневно с 10:00 до 22:00</span></p>

<h3>Схема проезда</h3>
<p>Наш адрес:
<span itemprop="address" itemscope itemtype="http://schema.org/PostalAddress">
<span itemprop="postalCode">443001</span>
<span itemprop="addressCountry">Россия</span>,
<span itemprop="addressRegion">Самарская обл.</span>,
<span itemprop="addressLocality">г. Новокуйбышевск</span>,
<span itemprop="streetAddress">ул. Суворова, д. 2</span>
</span>
</p>

<h3>Юридическая информация</h3>
<p>ИП Клешнин Илья Валерьевич<br>
ОГРНИП: 321631200002115</p>
</div>
HTML;

    $pdo = Database::pdo();
    $st = $pdo->prepare(
        'UPDATE base_page_categories SET content = ?
         WHERE (link = ? OR linkRW = ?)
           AND (content IS NULL OR TRIM(content) = \'\')'
    );
    $st->execute([$html, 'contactsart', '/contacts/']);
    $n = $st->rowCount();

    return ['updated' => $n, 'skipped' => $n > 0 ? 0 : 1];
};
