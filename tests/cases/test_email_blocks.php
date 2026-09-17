<?php
require __DIR__ . '/functions.php';

$failures = [];
function check(bool $cond, string $msg, array &$failures): void {
    if (!$cond) $failures[] = "✗ $msg";
}

// default_email_blocks(): punto di partenza per un nuovo template
// nell'editor visivo, deve sempre includere un blocco gift_box.
$defaults = default_email_blocks();
check(count($defaults) > 0, 'default_email_blocks() non è vuoto', $failures);
check(in_array('gift_box', array_column($defaults, 'type'), true), 'default_email_blocks() include un blocco gift_box', $failures);
check(($defaults[0]['width'] ?? null) === 150, 'default_email_blocks() imposta una larghezza di default per il blocco logo', $failures);

// render_blocks_to_html(): {{items}} nel corpo, item_html col box sconto,
// segnaposto liberi nei testi (heading/text/footer) preservati per lo
// strtr() successivo di render_email_template(), colore applicato.
$blocks = [
    ['type' => 'logo', 'align' => 'left', 'width' => 220],
    ['type' => 'heading', 'text' => 'Ciao {{nome}}!', 'align' => 'center', 'color' => '#111111'],
    ['type' => 'text', 'text' => 'Intro', 'align' => 'right', 'color' => '#222222'],
    ['type' => 'divider'],
    ['type' => 'spacer', 'height' => 30],
    ['type' => 'gift_box', 'label' => 'Per:', 'button_text' => 'Vai'],
    ['type' => 'footer', 'text' => '© {{anno}} {{business_name}}'],
];
$rendered = render_blocks_to_html($blocks, '#00AA00');
check(strpos($rendered['body_html'], '{{items}}') !== false, 'render_blocks_to_html inserisce {{items}} al posto del blocco gift_box', $failures);
check(strpos($rendered['body_html'], '{{logo}}') !== false, 'render_blocks_to_html inserisce {{logo}} per il blocco logo', $failures);
check(strpos($rendered['body_html'], 'text-align:left') !== false, 'render_blocks_to_html rispetta l\'allineamento del blocco logo', $failures);
check($rendered['logo_width'] === 220, 'render_blocks_to_html riporta la larghezza del blocco logo nel valore di ritorno', $failures);
check(strpos($rendered['body_html'], 'Ciao {{nome}}!') !== false, 'render_blocks_to_html preserva i segnaposto nel testo del blocco heading', $failures);
check(strpos($rendered['body_html'], 'color:#111111') !== false, 'render_blocks_to_html applica il colore del blocco heading', $failures);
check(strpos($rendered['body_html'], 'text-align:right') !== false, 'render_blocks_to_html rispetta l\'allineamento del blocco text', $failures);
check(strpos($rendered['body_html'], '<hr') !== false, 'render_blocks_to_html genera un divisore per il blocco divider', $failures);
check(strpos($rendered['body_html'], 'height:30px') !== false, 'render_blocks_to_html rispetta l\'altezza del blocco spacer', $failures);
check(strpos($rendered['body_html'], '© {{anno}} {{business_name}}') !== false, 'render_blocks_to_html preserva i segnaposto nel blocco footer', $failures);
check(strpos($rendered['item_html'], 'Per:') !== false && strpos($rendered['item_html'], 'Vai') !== false, 'render_blocks_to_html usa label/button_text del blocco gift_box', $failures);
check(strpos($rendered['item_html'], '{{colore}}') !== false, 'render_blocks_to_html mantiene {{colore}} nell\'item_html (sostituito da render_email_template)', $failures);

// Escaping: un blocco con testo che contiene HTML/script non deve finire
// non-escapato nell'HTML finale (stessa garanzia di render_email_template,
// qui applicata al testo scritto nell'editor a blocchi).
$xss_blocks = [['type' => 'text', 'text' => '<script>alert(1)</script>', 'align' => 'center', 'color' => '#000000']];
$xss_rendered = render_blocks_to_html($xss_blocks, '#D64545');
check(strpos($xss_rendered['body_html'], '<script>') === false, 'render_blocks_to_html esegue escaping HTML nel testo dei blocchi', $failures);
check(strpos($xss_rendered['body_html'], '&lt;script&gt;') !== false, 'render_blocks_to_html mostra il testo escapato come testo letterale', $failures);

// Nessun blocco gift_box fornito: deve comunque comparire un item_html di
// fallback, altrimenti un template non avrebbe mai spazio per i codici.
$no_gift = render_blocks_to_html([['type' => 'heading', 'text' => 'Solo un titolo']], '#D64545');
check(strpos($no_gift['body_html'], '{{items}}') !== false, 'render_blocks_to_html aggiunge {{items}} anche senza blocco gift_box esplicito', $failures);
check(strpos($no_gift['item_html'], '{{code}}') !== false, 'render_blocks_to_html genera un item_html di fallback senza blocco gift_box', $failures);

// sanitize_email_blocks(): scarta tipi sconosciuti, tiene solo il primo
// gift_box, applica limiti di lunghezza/valori.
$dirty = [
    ['type' => 'gift_box', 'label' => 'Primo'],
    ['type' => 'gift_box', 'label' => 'Secondo'],
    ['type' => 'tipo_inventato', 'text' => 'scartami'],
    ['type' => 'heading', 'text' => str_repeat('x', 500), 'align' => 'diagonale'],
    ['type' => 'spacer', 'height' => 9999],
    ['type' => 'logo', 'width' => 99999],
    'non un array',
];
$clean = sanitize_email_blocks($dirty);
check(count($clean) === 4, 'sanitize_email_blocks scarta i tipi sconosciuti e gli elementi non validi (' . count($clean) . ' invece di 4)', $failures);
check($clean[0]['type'] === 'gift_box' && $clean[0]['label'] === 'Primo', 'sanitize_email_blocks tiene solo il primo blocco gift_box', $failures);
check($clean[1]['align'] === 'center', 'sanitize_email_blocks ricade su align=center per un valore non valido', $failures);
check(mb_strlen($clean[1]['text']) === 200, 'sanitize_email_blocks tronca il testo troppo lungo', $failures);
check($clean[2]['height'] === 120, 'sanitize_email_blocks limita l\'altezza dello spacer al massimo consentito', $failures);
check($clean[3]['width'] === 400, 'sanitize_email_blocks limita la larghezza del logo al massimo consentito', $failures);

// Round-trip completo: save_email_template()/load_email_templates()
// conservano i blocchi (modalità editor visivo) o li azzerano a null
// (modalità codice), coerentemente con quanto fa dashboard.php.
save_email_template('bx', [
    'nome' => 'Blocchi', 'subject' => 'Oggetto', 'colore' => '#D64545',
    'body_html' => $rendered['body_html'], 'item_html' => $rendered['item_html'],
    'is_default' => false, 'blocks' => $blocks, 'logo_width' => $rendered['logo_width'],
]);
$loaded = load_email_templates()['bx'];
check($loaded['blocks'] === $blocks, 'save_email_template/load_email_templates conservano i blocchi in modalità editor visivo', $failures);
check($loaded['logo_width'] === 220, 'save_email_template/load_email_templates conservano la larghezza del logo', $failures);

save_email_template('bx', [
    'nome' => 'Blocchi', 'subject' => 'Oggetto', 'colore' => '#D64545',
    'body_html' => '<p>a mano</p>', 'item_html' => '<b>{{code}}</b>',
    'is_default' => false, 'blocks' => null,
]);
$loaded2 = load_email_templates()['bx'];
check($loaded2['blocks'] === null, 'save_email_template azzera i blocchi quando si salva in modalità codice', $failures);
check($loaded2['body_html'] === '<p>a mano</p>', 'save_email_template in modalità codice usa l\'HTML fornito direttamente', $failures);
check($loaded2['logo_width'] === 150, 'save_email_template ricade sulla larghezza logo di default (150) senza blocchi', $failures);

// render_email_template(): $logo_src distingue anteprima (URL reale, un
// browser non risolve "cid:") da invio reale (allegato incorporato via
// PHPMailer). La larghezza segue $template['logo_width'], l'altezza resta
// automatica per non deformare l'immagine.
$logo_tpl = ['colore' => '#D64545', 'logo_width' => 220, 'body_html' => '{{logo}}', 'item_html' => ''];
$preview_render = render_email_template($logo_tpl, 'Azienda', 'Mario', [], true, 'logo.png?v=42');
check(strpos($preview_render['html'], 'src="logo.png?v=42"') !== false, 'render_email_template usa un URL reale per il logo quando $logo_src è specificato (anteprima)', $failures);
check(strpos($preview_render['html'], 'cid:') === false, 'render_email_template non lascia "cid:" residuo quando si passa un $logo_src esplicito', $failures);
check(strpos($preview_render['html'], 'max-width:220px') !== false, 'render_email_template applica la larghezza del template al logo', $failures);
check(strpos($preview_render['html'], 'height:auto') !== false, 'render_email_template mantiene le proporzioni del logo (height:auto)', $failures);

$send_render = render_email_template($logo_tpl, 'Azienda', 'Mario', [], true);
check(strpos($send_render['html'], 'cid:logo_cid') !== false, 'render_email_template usa "cid:logo_cid" di default (invio reale, logo incorporato)', $failures);

if ($failures) {
    fwrite(STDERR, implode("\n", $failures) . "\n");
    exit(1);
}
exit(0);
