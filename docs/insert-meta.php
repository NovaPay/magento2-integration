<?php

require_once __DIR__ . '/vendor/autoload.php';

$search = "<meta charset=\"utf-8\">
<meta";

$replace = "<meta charset=\"utf-8\">
<meta property=\"og:image\" content=\"%s/images/en/install-%s.png\" />
<meta";

$host = 'http://magento-dev.sprinterra.com/docs';

$replaceTitle = '<title>INSTALL.%s</title>';

$languages = [
    'en' => 'Magento 2 Novapay Payment Gateway Installation Manual', 
    'ru' => 'Руководство по установке платежного шлюза Novapay в Magento 2', 
    'uk' => 'Посібник із встановлення платіжного шлюзу Novapay в Magento 2'
];

foreach ($languages as $language => $title) {
    $fileMD   = realpath(__DIR__ . "/INSTALL.$language.md"); 
    $fileHTML = realpath(__DIR__ . "/INSTALL.$language.html");
    $fileOut  = __DIR__ . "/guide.$language.html";

    printf("%s\n  > %s\n", $fileMD, $fileHTML);

    // $text = file_get_contents($fileMD);
    // $md = new Parsedown();
    // $html = $md->text($text);
    // file_put_contents($fileHTML, $html);
    
    $content  = file_get_contents($fileHTML);
    $updated  = sprintf($replace, $host, $language);
    $content  = str_replace($search, $updated, $content);
    $content  = str_replace(
        sprintf($replaceTitle, $language),
        "<title>$title</title>",
        $content
    );
    
    file_put_contents($fileOut, $content);
    printf("  > %s\n", $fileOut);
}
