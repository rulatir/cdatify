<?php

function htmlTemplate(array $substitutions=[]) : array
{
    $html = <<<HTML
<!DOCTYPE html>
<html lang="%%%source-language%%%">
	<head>
        <meta charset="UTF-8">
		<title translate="no">String catalog</title>
	</head>
	<body
	    id="the-body"
	    data-original="%%%original%%%"
	    data-source-language="%%%source-language%%%"
	    data-target-language="%%%target-language%%%"
	    data-long-tags="%%%use-long-tags%%%"
	    data-merge-duplicates="no"
    >
		%%%ITEMS%%%
	</body>
</html>
HTML;

    $item=<<<ITEM
        <section data-loco-id="%%%id%%%" data-resname="%%%resname%%%">%%%value%%%</section>
ITEM;
    return [str_replace(array_keys($substitutions), array_values($substitutions), $html), $item];
}


function htmlTemplateWithDeduplication(array $substitutions=[]) : array
{
    $html = <<<HTML
<!DOCTYPE html>
<html lang="%%%source-language%%%">
    <head>
        <meta charset="UTF-8">
        <title translate="no">String catalog (duplicate sets)</title>
        <body
            id="the-body"
            data-original="%%%original%%%"
            data-source-language="%%%source-language%%%"
            data-target-language="%%%target-language%%%"
            data-long-tags="%%%use-long-tags%%%"
            data-merge-duplicates="yes"
        >
            %%%ITEMS%%%        
        </body>    
    </head>
</html>
HTML;
    $item=<<<ITEM
    <section class="duplicate-set">
        <ul class="ids" translate="no">
            %%%IDS%%%
        </ul>
        <section class="value">%%%value%%%</section>
    </section>
ITEM;

    $id = <<<ID
    <li data-loco-id="%%%id%%%" data-resname="%%%resname%%%"></li>
ID;

    [$k,$v] = [array_keys($substitutions),array_values($substitutions)];
    return [
        str_replace($k,$v, $html),
        str_replace($k,$v,$item),
        str_replace($k,$v,$id)
    ];
}

function xlfTemplate(array $substitutions): array
{
    $xlf = <<<XLF
<?xml version="1.0" encoding="UTF-8"?>
<xliff xmlns="urn:oasis:names:tc:xliff:document:1.2" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" version="1.2" xsi:schemaLocation="urn:oasis:names:tc:xliff:document:1.2 http://docs.oasis-open.org/xliff/v1.2/os/xliff-core-1.2-strict.xsd">
  <file original="%%%original%%%" source-language="%%%source-language%%%" target-language="%%%target-language%%%" datatype="database" tool-id="loco">
    <header>
    <tool tool-id="loco" tool-name="Loco" tool-version="1.0.26 20220711-1" tool-company="Loco"></tool></header>
    <body>
      %%%ITEMS%%%
    </body>
  </file>
</xliff>
XLF;
    $item = <<<ITEM
      <trans-unit id="%%%id%%%" resname="%%%resname%%%" datatype="plaintext">
        <source/>
        <target>%%%value%%%</target>
      </trans-unit>
ITEM;

    return [str_replace(array_keys($substitutions), array_values($substitutions), $xlf), $item];
}

