<?php
require __DIR__ . '/functions.php';

$failures = [];
function check(bool $cond, string $msg, array &$failures): void {
    if (!$cond) $failures[] = "✗ $msg";
}

// Su installazione fresca, la prima chiamata a db() migra i vecchi campi
// config (qui assenti) creando un template "Italiano" di default.
$templates = load_email_templates();
check(count($templates) === 1 && isset($templates['it']), 'migrazione crea un template "it" su installazione fresca', $failures);
check($templates['it']['is_default'] === true, 'il template migrato è marcato come predefinito', $failures);
check(strpos($templates['it']['subject'], '{{business_name}}') !== false, 'il template migrato contiene i segnaposto attesi nell\'oggetto', $failures);

// La migrazione non deve rieseguirsi/duplicare al secondo avvio.
migrate_email_templates_if_needed(db());
check(count(load_email_templates()) === 1, 'la migrazione non duplica il template su chiamate successive', $failures);

// Creazione di un secondo template, non predefinito.
save_email_template('en', [
    'nome' => 'English', 'subject' => 'Your gifts from {{business_name}}',
    'colore' => '#123456', 'body_html' => '<p>{{nome}}</p>{{items}}',
    'item_html' => '<b>{{code}}</b> {{desc}}', 'is_default' => false,
]);
$templates = load_email_templates();
check(count($templates) === 2, 'due template dopo save_email_template', $failures);
check($templates['it']['is_default'] === true, 'il vecchio predefinito resta tale finché non se ne sceglie uno nuovo', $failures);

// Promuovere "en" a predefinito toglie il flag a "it".
$en_tpl = $templates['en'];
$en_tpl['is_default'] = true;
save_email_template('en', $en_tpl);
$templates = load_email_templates();
check($templates['en']['is_default'] === true, 'nuovo template promosso a predefinito', $failures);
check($templates['it']['is_default'] === false, 'il vecchio predefinito perde il flag (al più uno predefinito)', $failures);

// get_email_template: lingua esatta, poi predefinito, poi primo disponibile.
check(get_email_template('it')['nome'] === 'Italiano', 'get_email_template ritorna la lingua richiesta se esiste', $failures);
check(get_email_template('fr')['nome'] === 'English', 'get_email_template ricade sul predefinito se la lingua non esiste', $failures);
check(get_email_template(null)['nome'] === 'English', 'get_email_template(null) ritorna il predefinito', $failures);

delete_email_template('en');
$templates = load_email_templates();
check(count($templates) === 1 && !isset($templates['en']), 'delete_email_template rimuove solo il template indicato', $failures);
check(get_email_template('qualunque')['nome'] === 'Italiano', 'get_email_template ricade sul primo disponibile se non c\'è predefinito', $failures);

// render_email_template: sostituzione segnaposto corpo + item, oggetto senza escaping HTML.
$tpl = [
    'nome' => 'Test', 'subject' => 'Ciao {{business_name}}', 'colore' => '#ABCDEF',
    'body_html' => '<div>{{logo}}{{nome}} - {{items}} - {{anno}} - {{colore}}</div>',
    'item_html' => '[{{desc}}|{{code}}|{{url}}|{{label}}|{{colore}}]',
    'is_default' => true,
];
$rendered = render_email_template($tpl, 'Az<i>enda', 'Mario & Rossi', sample_regali_finali(), false);
check(strpos($rendered['html'], 'Mario &amp; Rossi') !== false, 'render_email_template esegue escaping HTML nel corpo', $failures);
check(strpos($rendered['html'], '[Evento di Esempio|GIFT-PREVIEW|#|100%|#ABCDEF]') !== false, 'render_email_template sostituisce correttamente i segnaposto item', $failures);
check(strpos($rendered['html'], date('Y')) !== false, 'render_email_template sostituisce {{anno}}', $failures);
check(strpos($rendered['html'], '<h1') !== false, 'render_email_template usa il fallback testuale per il logo se assente', $failures);
check($rendered['subject'] === 'Ciao Az<i>enda', 'render_email_template NON esegue escaping HTML nell\'oggetto (va in header email)', $failures);
check(strpos($rendered['text'], '<') === false, 'render_email_template produce un AltBody senza tag HTML', $failures);

$rendered_logo = render_email_template($tpl, 'Azienda', 'Mario', sample_regali_finali(), true);
check(strpos($rendered_logo['html'], 'cid:logo_cid') !== false, 'render_email_template usa il logo incorporato quando presente', $failures);

if ($failures) {
    fwrite(STDERR, implode("\n", $failures) . "\n");
    exit(1);
}
exit(0);
