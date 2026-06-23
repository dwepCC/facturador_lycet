<?php

final class PostInstall
{
    public static function run()
    {
        self::patchGre31DespatchPartyTemplate();

        if (!getenv('LYCET_BETA')) {
            return;
        }

        self::copyCertLogo();
        self::installWkthmltopdf();
    }

    /**
     * GRE transportista (31): SUNAT exige remitente en cac:DespatchParty (error 3383).
     * Greenter 5.3 solo lo incluía en SellerSupplierParty; parche idempotente en plantilla UBL.
     */
    private static function patchGre31DespatchPartyTemplate(): void
    {
        $path = __DIR__.'/../vendor/greenter/xml/src/Xml/Templates/despatch2022.xml.twig';
        if (!is_file($path)) {
            return;
        }
        $content = file_get_contents($path);
        if ($content === false || str_contains($content, 'DespatchParty')) {
            return;
        }
        $needle = "\t\t\t</cac:DespatchAddress>\r\n\t\t\t</cac:Despatch>";
        $insert = "\t\t\t</cac:DespatchAddress>\r\n\t\t\t{% if doc.tipoDoc == '31' and doc.tercero %}\r\n\t\t\t<cac:DespatchParty>\r\n\t\t\t\t<cac:PartyIdentification>\r\n\t\t\t\t\t<cbc:ID schemeID=\"{{ doc.tercero.tipoDoc }}\" schemeName=\"Documento de Identidad\" schemeAgencyName=\"PE:SUNAT\" schemeURI=\"urn:pe:gob:sunat:cpe:see:gem:catalogos:catalogo06\">{{ doc.tercero.numDoc }}</cbc:ID>\r\n\t\t\t\t</cac:PartyIdentification>\r\n\t\t\t\t<cac:PartyLegalEntity>\r\n\t\t\t\t\t<cbc:RegistrationName><![CDATA[{{ doc.tercero.rznSocial|raw }}]]></cbc:RegistrationName>\r\n\t\t\t\t</cac:PartyLegalEntity>\r\n\t\t\t</cac:DespatchParty>\r\n\t\t\t{% endif %}\r\n\t\t\t</cac:Despatch>";
        if (!str_contains($content, $needle)) {
            $needle = "\t\t\t</cac:DespatchAddress>\n\t\t\t</cac:Despatch>";
        }
        if (!str_contains($content, $needle)) {
            echo 'WARN: no se pudo parchear despatch2022.xml.twig (GRE 31 DespatchParty)'.PHP_EOL;
            return;
        }
        file_put_contents($path, str_replace($needle, $insert, $content, $count));
        if ($count > 0) {
            echo 'Parche GRE 31 DespatchParty aplicado en despatch2022.xml.twig'.PHP_EOL;
        }
    }

    private static function copyCertLogo()
    {
        $logoPath = __DIR__.'/../tests/Resources/logo.png';
        $certPath = __DIR__.'/../tests/Resources/SFSCert.pem';

        echo 'Copiar certificado, logo para pruebas.'.PHP_EOL;
        copy($logoPath, __DIR__.'/../data/logo.png');
        copy($certPath, __DIR__.'/../data/cert.pem');
    }

    private static function installWkthmltopdf()
    {
        if (self::inPath('wkhtmltopdf')) {
            echo 'Wkhtmltopdf global install found.'.PHP_EOL;
            return;
        }

        $pathBin = self::getPathBin();
        if (file_exists($pathBin)) {
            echo $pathBin . PHP_EOL;
            return;
        }

        $url = self::getUrlDownload(self::isWindows(), self::is64Bit());

        if (!is_dir( __DIR__.'/../vendor/bin')) {
            $oldMask = umask(0);
            mkdir(__DIR__.'/../vendor/bin', 0777, true);
            umask($oldMask);
        }
        self::downloadBin($url, $pathBin);
    }

    private static function is64Bit()
    {
        $value = php_uname('m'); // Tipo de máquina. ej. i386

        return strpos($value, '64') !== false;
    }

    private static function getUrlDownload($windows, $x64)
    {
        $domain = 'https://raw.githubusercontent.com/';
        if ($windows) {
            $path = $x64
                ? 'wemersonjanuario/wkhtmltopdf-windows/master/bin/wkhtmltopdf64.exe'
                : 'wemersonjanuario/wkhtmltopdf-windows/master/bin/wkhtmltopdf32.exe';
        } else {
            $path = $x64
                ? 'h4cc/wkhtmltopdf-amd64/master/bin/wkhtmltopdf-amd64'
                : 'h4cc/wkhtmltopdf-i386/master/bin/wkhtmltopdf-i386';
        }

        return $domain.$path;
    }

    private static function downloadBin($url, $localPath)
    {
        echo 'Downloading... '.$url.PHP_EOL;
        $bin = file_get_contents($url);

        echo 'Writing in '.$localPath.PHP_EOL;
        file_put_contents($localPath, $bin);
        chmod($localPath, 0777);
        echo exec("$localPath --version").PHP_EOL;

        echo 'FILE SIZE: '. number_format(filesize($localPath)/1048576, 2).' MB'.PHP_EOL;
    }

    public static function inPath($command) {
        $whereIsCommand = self::isWindows() ? 'where' : 'which';

        $process = proc_open(
            "$whereIsCommand $command",
            array(
                0 => array("pipe", "r"), //STDIN
                1 => array("pipe", "w"), //STDOUT
                2 => array("pipe", "w"), //STDERR
            ),
            $pipes
        );
        if ($process !== false) {
            $stdout = stream_get_contents($pipes[1]);
            stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($process);

            return $stdout != '';
        }

        return false;
    }

    public static function getPathBin()
    {
        $path = __DIR__.'/../vendor/bin/wkhtmltopdf';
        if (self::isWindows()) {
            $path .= '.exe';
        }

        return $path;
    }

    public static function isWindows()
    {
        return strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';
    }
}

PostInstall::run();