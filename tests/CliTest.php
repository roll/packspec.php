<?php
namespace packspec\packspec\tests;
use PHPUnit\Framework\TestCase;
require('src/cli.php');


class CliTest extends TestCase {

    public function testPackspec() {

        // Get specs
        $specs = \packspec\packspec\parse_specs('tests/packspec.yml');

        // Valid
        $valid = \packspec\packspec\test_specs($specs);
        $this->assertTrue($valid);

        // Assertion fail
        $specs[0]['features'] = array_slice($specs[0]['features'], 0, 3);
        $specs[0]['features'][2]['result'] = 'FAIL';
        $valid = \packspec\packspec\test_specs($specs);
        $this->assertFalse($valid);

        // Exception fail
        $specs[0]['features'] = array_slice($specs[0]['features'], 0, 3);
        $specs[0]['features'][2]['call'] = true;
        $valid = \packspec\packspec\test_specs($specs);
        $this->assertFalse($valid);

    }

}
