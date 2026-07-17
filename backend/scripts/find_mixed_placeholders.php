<?php
$root = __DIR__ . '/..';
$rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
$files = [];
foreach ($rii as $file) {
    if ($file->isDir()) continue;
    $path = $file->getPathname();
    if (strpos($path, DIRECTORY_SEPARATOR . 'backend' . DIRECTORY_SEPARATOR) === false) continue;
    if (substr($path, -4) !== '.php') continue;
    $files[] = $path;
}
// Continue to detect prepare($sqlVar) where $sqlVar was assigned earlier as a literal
$more = [];
foreach ($files as $file) {
    $content = file_get_contents($file);
    if ($content === false) continue;
    if (preg_match_all('/->prepare\s*\(\s*\$([a-zA-Z_][a-zA-Z0-9_]*)\s*\)/', $content, $m, PREG_OFFSET_CAPTURE)) {
        foreach ($m[1] as $idx => $varOcc) {
            $varName = $varOcc[0];
            $pos = $varOcc[1];
            // search backwards for a $varName = '...'; within previous 2000 chars
            $start = max(0, $pos - 2000);
            $segment = substr($content, $start, $pos - $start);
            if (preg_match('/\$' . preg_quote($varName, '/') . '\s*=\s*("(?:(?:\\.|[^"\\])*)"|\'(?:(?:\\.|[^'\\])*)\')/s', $segment, $mm)) {
                $lit = $mm[1];
                $litUnq = substr($lit,1,-1);
                $litUnq = stripcslashes($litUnq);
                if (strpos($litUnq, '?') !== false && preg_match('/:[A-Za-z_]/', $litUnq)) {
                    $more[] = ['file'=>$file,'var'=>$varName,'context'=>trim(substr($content, max(0, $start + strpos($segment,$lit)-120), 600))];
                }
            }
        }
    }
}

// Merge results
$all = array_merge($results, $more);
if (empty($all)) {
    echo "OK: no mixed placeholders found in prepare() statements with literal SQL or prior sql assignments\n";
    exit(0);
}
foreach ($all as $r) {
    if (isset($r['var'])) {
        echo "File(var): " . $r['file'] . " var=" . $r['var'] . "\n";
    } else {
        echo "File: " . $r['file'] . "\n";
    }
    echo $r['context'] . "\n---\n";
}
exit(0);
                $pos = $varOcc[1];
                // search backwards for a $varName = '...'; within previous 1000 chars
                $start = max(0, $pos - 2000);
                $segment = substr($content, $start, $pos - $start);
                if (preg_match('/\$' . preg_quote($varName, '/') . '\s*=\s*("(?:(?:\\\\.|[^"\\\\])*)"|\'(?:(?:\\\\.|[^'\\\\])*)\')/s', $segment, $mm)) {
                    $lit = $mm[1];
                    $litUnq = substr($lit,1,-1);
                    $litUnq = stripcslashes($litUnq);
                    if (strpos($litUnq, '?') !== false && preg_match('/:[A-Za-z_]/', $litUnq)) {
                        $more[] = ['file'=>$file,'var'=>$varName,'context'=>trim(substr($content, max(0, $start + strpos($segment,$lit)-120), 600))];
                    }
                }
            }
        }
    }

    if (!empty($more)) {
        foreach ($more as $r) {
            echo "File(var): " . $r['file'] . " var=$r[var]\n";
            echo $r['context'] . "\n---\n";
        }
        exit(0);
    }

    echo "OK: no mixed placeholders found in prepare() statements with literal SQL or prior $sql assignments\n";
    exit(0);
