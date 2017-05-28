<?php
namespace roll\packspec;
use Colors\Color;
use Spatie\Emoji\Emoji;
use Symfony\Component\Yaml\Yaml;
require_once('vendor/autoload.php');


// Helpers

function parse_specs($path) {

    // Specs
    $specmap = [];
    // TODO: support recursive glob
    foreach(glob("{$path}/*.yml") as $filepath) {
        $filecont = file_get_contents($filepath);
        $spec = parse_spec($filecont);
        if (!$spec) {
            continue;
        } else if (!in_array($spec['package'], $specmap)) {
            $specmap[$spec['package']] = $spec;
        } else {
            array_merge($specmap[$spec['package']]['features'], $spec['features']);
        }
    }

    // Hooks
    // TODO: implement

    // Result
    // TODO: sort
    $specs = array_values($specmap);

    return $specs;

}


function parse_spec($spec) {

    // Package
    $contents = Yaml::parse($spec);
    try {
        $feature = parse_feature($contents[0]);
        $package = $feature['result'];
        assert($feature['assign'] == 'PACKAGE');
        assert(!$feature['skip']);
    } catch (Exception $exception) {
        return null;
    }

    // Features
    $features = [];
    foreach($contents as $feature){
        $feature = parse_feature($feature);
        array_push($features, $feature);
    }

    // Scope
    $scope = [];
    $fcqns = @get_all_fqcns();
    foreach($fcqns as $item) {
        if (strpos($item, "\\{$package}\\") != false) {
            $name = array_values(array_slice(explode('\\', $item), -1))[0];
            $scope[$name] = $item;
        }
    }

    return [
        'package' => $package,
        'features' => $features,
        'scope' => $scope,
    ];

}


function parse_feature($feature) {
    if (gettype($feature) == 'string') {
        return ['comment' => $feature];
    }
    foreach(array_slice($feature, 0, 1) as $key => $value) {
        $left = $key;
        $right = $value;
    }

    // Left side
    $left = camelize($left);
    $call = false;
    preg_match('/^(?:(.*):)?(?:([^=]*)=)?([^=].*)?$/', $left, $match);
    $skip = isset($match[1]) ? $match[1] : null;
    $assign = isset($match[2]) ? $match[2] : null;
    $property = isset($match[3]) ? $match[3] : null;
    if ($skip) {
        $filters = explode(':', $skip);
        $skip = ($filters[0] == 'not') == in_array('php', $filters);
    }
    if (!$assign && !$property) {
        throw new Exception('Non-valid feature');
    }
    if ($property) {
        $call = true;
        if (substr($property, -2) == '==') {
            $property = substr($property, 0, -2);
            $call = false;
        }
    }

    // Right side
    $args = [];
    $kwargs = [];
    $result = $right;
    if ($call) {
        $result = null;
        foreach($right as $item) {
            if (gettype($item) == 'array' && count($item) == 1) {
                foreach(array_slice($item, 0, 1) as $key => $value) {
                    $item_left = $key;
                    $item_right = $value;
                }
                if ($item_left == '==') {
                    $result = $item_right;
                    continue;
                }
                if (substr($item_left, -1) == '=') {
                    $kwargs[substr($item_left, 0, -1)] = $item_right;
                }
            }
            array_push($args, $item);
        }
    }

    // Text repr
    $text = $property;
    if ($assign) {
        $value = ($property) ? $property : json_encode($result);
        $text = "{$assign} = {$value}";
    }
    if ($call) {
        $items = [];
        foreach($args as $item) {
            array_push($items, json_encode($item));
        }
        foreach($kwargs as $name => $item) {
            $value = json_encode($item);
            array_push($items, "{$name}={$value}");
        }
        $items = implode(', ', $items);
        $text = "{$text}({$items})";
    }
    if ($result and !$assign) {
        $result_text = json_encode($result);
        $text = "{$text} == ${result_text}";
    }
    // TODO: replace
    // text = re.sub(r'{"([^{}]*?)": null}', r'\1', text)

    return [
        'comment' => null,
        'skip' => $skip,
        'call' => $call,
        'assign' => $assign,
        'property' => $property,
        'args' => $args,
        'kwargs' => $kwargs,
        'result' => $result,
        'text' => $text,
    ];

}


function test_specs($specs) {
    $success = true;
    $colorize = new Color();
    $message = $colorize("\n #  PHP\n")->bold() . PHP_EOL;
    print($message);
    foreach($specs as $spec) {
        $spec_success = test_spec($spec);
        $success = $success and $spec_success;
    }
    return $success;
}


function test_spec($spec) {
    $passed = 0;
    $amount = count($spec['features']);
    $message = str_repeat(Emoji::heavyMinusSign(), 3) . "\n";
    print($message);
    foreach($spec['features'] as $feature) {
        $passed += test_feature($feature, $spec['scope']);
    }
    $success = ($passed == $amount);
    $color = 'green';
    $colorize = new Color();
    $message = $colorize("\n\n " . Emoji::heavyCheckMark() . '  ')->green()->bold() . '';
    if (!$success) {
        $color = 'red';
        $message = $colorize("\n\n " . Emoji::crossMark() . '  ')->red()->bold() . '';
    }
    $message .= $colorize("{$spec['package']}: {$passed}/{$amount}\n")->fg($color)->bold() . PHP_EOL;
    print($message);
    return $success;
}


function test_feature($feature, &$scope) {

    // Comment
    if ($feature['comment']) {
        $colorize = new Color();
        $message = "\n #  ";
        $message .= $colorize($feature['comment'])->bold() . "\n\n";
        print($message);
        return true;
    }

    // Skip
    if ($feature['skip']) {
        $colorize = new Color();
        $message = $colorize(' ' . Emoji::heavyMinusSign() . '  ')->yellow();
        $message .= $feature['text'] . "\n";
        print($message);
        return true;
    }

    // Execute
    // TODO: dereference feature
    $result = $feature['result'];
    if ($feature['property']) {
        try {
            // TODO: implement properly and with nested attributes
            if (strpos($feature['property'], '.')) {
                $parts = explode('.', $feature['property']);
                $object = $scope[$parts[0]];
                $method = $parts[1];
                if ($feature['call']) {
                    $result = $object->$method(...$feature['args']);
                } else {
                    $result = $object->$method();
                }
            } else {
                $property = $scope[$feature['property']];
                if ($feature['call']) {
                    if (gettype($property) == 'string') {
                        // TODO: REMOVE! only to showcase for tableschema
                        $feature['args'][0] = (object)$feature['args'][0];
                        $result = new $property(...$feature['args']);
                    } else {
                        $result = $property(...$feature['args']);
                    }
                } else {
                    $result = $property;
                }
            }
        } catch (Exception $exception) {
            $result = 'ERROR';
        }
    }

    // Assign
    if ($feature['assign']) {
        // TODO: implement nested assign
        // TODO: ensure immutable constants
        $scope[$feature['assign']] = $result;
    }

    // Compare
    // TODO: isoformat value
    $success = ($feature['result']) ? $result == $feature['result'] : $result != 'ERROR';
    if ($success) {
        $colorize = new Color();
        $message = $colorize(' ' . Emoji::heavyCheckMark() . '  ')->green();
        $message .= $feature['text'] . "\n";
        print($message);
    } else {
        try {
            $result_text = json_encode($result);
        } catch (Exception $exception) {
            $result_text = (string)$result;
        }
        $colorize = new Color();
        $message = $colorize(' ' . Emoji::crossMark() . '  ')->red();
        $message .= $feature['text'] . ' # ' . $result_text  . "\n";
        print($message);
    }

    return $success;
}


// https://stackoverflow.com/questions/22761554/php-get-all-class-names-inside-a-particular-namespace
function get_all_fqcns() {
    $path = '.';
    $fqcns = array();

    $allFiles = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path));
    $phpFiles = new \RegexIterator($allFiles, '/\.php$/');
    foreach ($phpFiles as $phpFile) {
        $content = file_get_contents($phpFile->getRealPath());
        $tokens = token_get_all($content);
        $namespace = '';
        for ($index = 0; isset($tokens[$index]); $index++) {
            if (!isset($tokens[$index][0])) {
                continue;
            }
            if (T_NAMESPACE === $tokens[$index][0]) {
                $index += 2; // Skip namespace keyword and whitespace
                while (isset($tokens[$index]) && is_array($tokens[$index])) {
                    $namespace .= $tokens[$index++][1];
                }
            }
            if (T_CLASS === $tokens[$index][0]) {
                $index += 2; // Skip class keyword and whitespace
                $fqcns[] = $namespace.'\\'.$tokens[$index][1];
            }
        }
    }
    return $fqcns;
}


function camelize($input, $separator = '_') {
    if (strpos($input, '_')) {
        $input = lcfirst(str_replace($separator, '', ucwords($input, $separator)));
    }
    return $input;
}


// Main program

$path = isset($argv[1]) ? $argv[1] : '.';
$specs = parse_specs($path);
$success = test_specs($specs);
