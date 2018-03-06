<?php

class PluginFixerPlugin extends Gdn_Plugin {

    public function settingscontroller_pluginfixer_create($Sender) {
        $Sender->permission("Garden.Moderation.Manage");
        $this->render("settings");
    }

    public function plugincontroller_pluginfixer_create($Sender) {
        $Sender->permission("Garden.Moderation.Manage");
        
        if (empty($_FILES['plugin_to_fix'])) {
            return;
        }

        $temp_path = $_FILES['plugin_to_fix']['tmp_name'];
        $zip = new ZipArchive();
        if ($zip->open($temp_path) !== TRUE) {
            return;
        }
        $key = false;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name_of_entry = $zip->getNameIndex($i);
            if ($this->isMainFile(strtolower($name_of_entry))) {
                $contents = $zip->getFromIndex($i);
                $addonjson = $this->parseContents($contents, $key);
                $zip->deleteName($name_of_entry);
                $match = strpos($name_of_entry, '/') === false ? '\\' : '/';
                $path = substr($name_of_entry, 0, strrpos($name_of_entry, $match)) . $match;
                $zip->addFromString($path . "class.$key.plugin.php", str_ireplace("class $key ", "class $key" . "Plugin ", $contents));
                $zip->addFromString($path . "addon.json", str_ireplace('"TRUE"', "true", str_ireplace('"FALSE"', "false", $addonjson)));
                $zip->close();
                break;
            }
        }
        header("Content-type: application/zip");
        header("Content-Disposition: attachment; filename=$key.zip");
        header("Content-length: " . filesize($temp_path));
        header("Pragma: no-cache");
        header("Expires: 0");
        readfile($temp_path);
        exit();
    }

    function isMainFile($name) {

        $infoPaths = [
            '/default.php', // plugin
            '/class.*.php',
            '/class.*.plugin.php'
        ];
        foreach ($infoPaths as $infoPath) {
            $preg = '`(' . str_replace(['.', '*'], ['\.', '.*'], $infoPath) . ')$`';
            if (preg_match($preg, $name, $matches)) {
                return true;
            }
        }
        return false;
    }

    function parseContents($contents, &$key) {
        $PluginInfo = ConvertInfoArray::parseInfoArray($contents);
        if ($PluginInfo) {
            $key = $PluginInfo['key'];
            $json = $this->convertInfo($PluginInfo['info'], $key);
            if ($json) {
                return $json;
            }
        }
        return false;
    }

    function convertInfo($PluginInfo, $key) {
        $newInfo = new stdClass();
        $newInfo->type = "addon";
        $newInfo->key = $key;
        $notToCopy = ["Author" => true, "AuthorUrl" => true, "AuthorEmail" => true, "RequiredApplications" => true, "RequiredPlugins" => true];
        foreach ($PluginInfo as $currentKey => $value) {
            if (isset($notToCopy[$currentKey])) {
                continue;
            }
            $newKey = strtolower($currentKey[0]) . substr($currentKey, 1);
            $newInfo->$newKey = $value;
        }
        $author = $this->getAuthor($PluginInfo);
        if ($author) {
            $newInfo->authors = [$author];
        }
        $requires = $this->getRequirements($PluginInfo);
        if ($requires) {
            $newInfo->require = $requires;
        }
        return json_encode($newInfo, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    function getRequirements($PluginInfo) {
        $requirements = [];
        if (isset($PluginInfo['RequiredApplications']) && is_array($PluginInfo['RequiredApplications'])) {
            $requirements = array_merge($requirements, $PluginInfo['RequiredApplications']);
        }
        if (isset($PluginInfo['RequiredPlugins']) && is_array($PluginInfo['RequiredPlugins'])) {
            $requirements = array_merge($requirements, $PluginInfo['RequiredPlugins']);
        }
        if ($requirements) {
            return $requirements;
        }
        return false;
    }

    function getAuthor($PluginInfo) {
        $author = [];
        if (isset($PluginInfo['Author'])) {
            $author['name'] = $PluginInfo['Author'];
        }
        if (isset($PluginInfo['AuthorEmail'])) {
            $author['email'] = $PluginInfo['AuthorEmail'];
        }
        if (isset($PluginInfo['AuthorUrl'])) {
            $author['homepage'] = $PluginInfo['AuthorUrl'];
        }
        if ($author) {
            return (object) $author;
        }
        return false;
    }

}

class ConvertInfoArray {
    /*
     * Copied largely from UpdateModel::parseInfoArray
     */

    public static function parseInfoArray($full_file, $variable = false) {
        $fp = tmpfile();
        fwrite($fp, $full_file);
        fseek($fp, 0);
        $lines = [];
        $inArray = false;
        $globalKey = '';

        // Get all of the lines in the info array.
        while (($line = fgets($fp)) !== false) {
            // Remove comments from the line.
            $line = preg_replace('`\s//.*$`', '', $line);
            if (!$line) {
                continue;
            }

            if (!$inArray && preg_match('`\$([A-Za-z]+Info)\s*\[`', trim($line), $matches)) {
                $variable = $matches[1];
                if (preg_match('`\[\s*[\'"](.+?)[\'"]\s*\]`', $line, $matches)) {
                    $globalKey = $matches[1];
                    $inArray = true;
                }
            } elseif ($inArray && stringEndsWith(trim($line), ';')) {
                break;
            } elseif ($inArray) {
                $lines[] = trim($line);
            }
        }
        fclose($fp);

        if (count($lines) == 0) {
            return false;
        }

        // Parse the name/value information in the arrays.
        $result = [];
        foreach ($lines as $line) {
            // Get the name from the line.
            if (!preg_match('`[\'"](.+?)[\'"]\s*=>`', $line, $matches) || !substr($line, -1) == ',') {
                continue;
            }
            $key = $matches[1];

            // Strip the key from the line.
            $line = trim(trim(substr(strstr($line, '=>'), 2)), ',');

            if (strlen($line) == 0) {
                continue;
            }

            $value = null;
            if (is_numeric($line)) {
                $value = $line;
            } elseif (strcasecmp($line, 'TRUE') == 0 || strcasecmp($line, 'FALSE') == 0)
                $value = $line;
            elseif (in_array($line[0], ['"', "'"]) && substr($line, -1) == $line[0]) {
                $quote = $line[0];
                $value = trim($line, $quote);
                $value = str_replace('\\' . $quote, $quote, $value);
            } elseif (stringBeginsWith($line, 'array(') && substr($line, -1) == ')') {
                // Parse the line's array.
                $line = substr($line, 6, strlen($line) - 7);
                $items = explode(',', $line);
                $array = [];
                foreach ($items as $item) {
                    $subItems = explode('=>', $item);
                    if (count($subItems) == 1) {
                        $array[] = trim(trim($subItems[0]), '"\'');
                    } elseif (count($subItems) == 2) {
                        $subKey = trim(trim($subItems[0]), '"\'');
                        $subValue = trim(trim($subItems[1]), '"\'');
                        $array[$subKey] = $subValue;
                    }
                }
                $value = $array;
            }

            if ($value != null) {
                $result[$key] = $value;
            }
        }
        return ["key" => $globalKey, "info" => $result];
    }

}
