<?php
namespace packspec\packspec\tests;
use PHPUnit\Framework\TestCase;
require('src/cli.php');


class CliTest extends TestCase {

    public function testPackspec()
    {
        $specs = \packspec\packspec\parse_specs('tests/packspec.yml');
        $valid = \packspec\packspec\test_specs($specs);
        $this->assertTrue($valid);
    }

}
