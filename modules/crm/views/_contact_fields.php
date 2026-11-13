<?php
declare(strict_types=1);
/** @var bool $canCreateClient */
?>
<label><?= te('crm.contact.name') ?><input name="name"<?= $canCreateClient ? '' : ' disabled' ?>></label>
<label><?= te('crm.contact.tel') ?><input name="tel" type="tel"></label>
<label><?= te('crm.contact.mail') ?><input name="mail" type="email"></label>
<label><?= te('crm.contact.telegram') ?><input name="telegram" placeholder="@username"></label>
<label><?= te('crm.contact.max') ?><input name="max"></label>
<label><?= te('crm.contact.whatsapp') ?><input name="whatsapp" type="tel"></label>
<p class="muted crm-span-2"><?= te('crm.contact.any_channel_hint') ?></p>
