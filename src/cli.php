<?php
namespace packspec\packspec;
use Colors\Color;
use Spatie\Emoji\Emoji;
use Symfony\Component\Yaml\Yaml;
require_once('vendor/autoload.php');


// Helpers

function parse_specs($path) {

    // Paths
    $paths = [];
    if ($path) {
        if (is_file($path)) {
            $paths = [path];
        } else if (is_dir($path)) {
            $paths = glob("{$path}/*.yml");
        }
    } else {
        if (!$paths) {$paths = glob("packspec.yml");}
        if (!$paths) {$paths = glob("packspec/*.yml");}
    }

    // Specs
    $specs = [];
    foreach($paths as $path) {
        $spec = parse_spec($path);
        if ($spec) {
            array_push($specs, $spec);
        }
    }

    return $specs;

}


function parse_spec($path) {
    // TODO: support user scope

    // Package
    $document = Yaml::parse(file_get_contents($path));
    try {
        $feature = parse_feature($document[0]);
        if ($feature['skip']) {return null;}
    } catch (\Exception $exception) {return null;}
    $package = $feature['comment'];

    // Features
    $skip = false;
    $features = [];
    foreach($document as $feature){
        $feature = parse_feature($feature);
        array_push($features, $feature);
        if ($feature['comment']) {
            $skip = $feature['skip'];
        }
        $skip = $skip || $feature['skip'];
    }

    // Scope
    $scope = [];
    $scope['$import'] = function ($package){return builtin_import($package);};

    // Stats
    $stats = ['features' => 0, 'comments' => 0, 'skipped' => 0, 'tests' => 0];
    foreach ($features as $feature) {
        $stats['features'] += 1;
        if ($feature['comment']) {
            $stats['comments'] += 1;
        } else {
            $stats['tests'] += 1;
            if ($feature['skip']) {
                $stats['skipped'] += 1;
            }
        }
    }

    return [
        'package' => $package,
        'features' => $features,
        'scope' => $scope,
        'stats' => $stats,
    ];

}


function parse_feature($feature) {

    # General
    if (gettype($feature) == 'string') {
        preg_match('/^(?:\((.*)\))?(\w.*)$/', $feature, $match);
        $skip = isset($match[1]) ? $match[1] : null;
        $comment = isset($match[2]) ? $match[2] : null;
        if ($skip) {
            $skip = !in_array('php', explode('|', $skip));
        }
        return ['comment' => $comment, 'skip' => $skip];
    }
    foreach(array_slice($feature, 0, 1) as $key => $value) {
        $left = $key;
        $right = $value;
    }

    // Left side
    $left = camelize($left);
    $call = false;
    preg_match('/^(?:\((.*)\))?(?:([^=]*)=)?([^=].*)?$/', $left, $match);
    $skip = isset($match[1]) ? $match[1] : null;
    $assign = isset($match[2]) ? $match[2] : null;
    $property = isset($match[3]) ? $match[3] : null;
    if ($skip) {
        $skip = !in_array('php', explode('|', $skip));
    }
    if (!$assign && !$property) {
        throw new \Exception('Non-valid feature');
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

    // Message
    $colorize = new Color();
    $message = $colorize("\n #  PHP\n")->bold() . PHP_EOL;
    print($message);


    // Tests specs
    $success = true;
    foreach($specs as $spec) {
        $spec_success = test_spec($spec);
        $success = $success and $spec_success;
    }

    return $success;
}


function test_spec($spec) {

    // Message
    $message = str_repeat(Emoji::heavyMinusSign(), 3) . "\n";
    print($message);

    // Test spec
    $passed = 0;
    foreach($spec['features'] as $feature) {
        $passed += test_feature($feature, $spec['scope']);
    }
    $success = ($passed == $spec['stats']['features']);

    // Message
    $color = 'green';
    $colorize = new Color();
    $message = $colorize("\n\n " . Emoji::heavyCheckMark() . '  ')->green()->bold() . '';
    if (!$success) {
        $color = 'red';
        $message = $colorize("\n\n " . Emoji::crossMark() . '  ')->red()->bold() . '';
    }
    $tests_passed = $passed - $spec['stats']['comments'] - $spec['stats']['skipped'];
    $tests_count = $spec['stats']['tests'] - $spec['stats']['skipped'];
    $message .= $colorize("{$spec['package']}: {$tests_passed}/{$tests_count}\n")->fg($color)->bold() . PHP_EOL;
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

    // Dereference
    // TODO: dereference arguments and result

    # Execute
    $exception = null;
    $result = $feature['result'];
    if ($feature['property']) {
        try {
            $property = $scope;
            foreach(explode('.', $feature['property']) as $name) {
                [$object, $property] = get_property($property, $name);
                if ($object) {break;}
            }
            if (is_string($object)) {
                if ($feature['call']) {
                    $result = $object::$property(...$feature['args']);
                } else {
                    $result = $object::$property();
                }
            } else if ($object) {
                if ($feature['call']) {
                    $result = $object->$property(...$feature['args']);
                } else {
                    $result = $object->$property();
                }
            } else {
                if ($feature['call']) {
                    if (gettype($property) == 'string') {
                        $result = new $property(...$feature['args']);
                    } else {
                        $result = $property(...$feature['args']);
                    }
                } else {
                    $result = $property;
                }
            }
        } catch (\Exception $exc) {
            $exception = $exc;
            $result = 'ERROR';
        }
    }

    // Assign
    if ($feature['assign']) {
        // TODO: implement nested assign
        $scope[$feature['assign']] = $result;
    }

    // Compare
    $success = ($feature['result']) ? $result == $feature['result'] : $result != 'ERROR';
    if ($success) {
        $colorize = new Color();
        $message = $colorize(' ' . Emoji::heavyCheckMark() . '  ')->green();
        $message .= $feature['text'] . "\n";
        print($message);
    } else {
        try {
            $result_text = json_encode($result);
        } catch (\Exception $exception) {
            $result_text = (string)$result;
        }
        $colorize = new Color();
        $message = $colorize(' ' . Emoji::crossMark() . '  ')->red();
        $message .= $feature['text'] . ' # ' . $result_text  . "\n";
        if ($exception) {
            $message .= $colorize("Exception: {$exception->getMessage()}\n")->red()->bold();
        } else {
            $feature_result_text = json_encode($feature['result']);
            $message .= $colorize("Assertion: {$result_text} != {$feature_result_text}\n")->red()->bold();
        }
        print($message);
    }

    return $success;
}


function builtin_import($package) {
    $attributes = [];
    $fcqns = @get_all_fqcns();
    foreach($fcqns as $item) {
        if (strpos($item, "\\{$package}\\") != false) {
            $name = array_values(array_slice(explode('\\', $item), -1))[0];
            $attributes[$name] = $item;
        }
    }
    return $attributes;
}


function get_property($owner, $name) {
    // print("\n---");
    // print("\n---");
    // print("\n---\n");
    // print_r($owner);
    // print("\n---\n");
    // print_r($name);
    // print("\n---");
    // print("\n---");
    // print("\n---\n");
    if (is_array($owner) && !array_key_exists($name, $owner)) {
        throw new \Exception("No {$name} in the scope");
    } else if (is_array($owner)) {
        $object = null;
        $property = $owner[$name];
    } else {
        $object = $owner;
        $property = $name;
    }
    return [$object, $property];
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
