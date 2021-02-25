<?php

// Source code of the Documentation are stored in .md files: INSTALL.<language>.md
// To convert .md files into .html follow next steps:
// 1. Open .md file with MacDown appliction and export it to .html > File > Export > HTML
// 2. run php script insert-meta.php > php docs/insert-meta.php
// 3. Use .html files as documentation with attached images/*

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
