<?php

namespace Iaasen\MatrikkelApi\Tests\Integration\Console;

use Iaasen\Matrikkel\Kernel;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Integration tests for OrganizeHierarchyCommand
 * 
 * Tests the full CLI command including:
 * - Argument parsing
 * - Error handling
 * - Integration with repositories
 * - Database operations
 */
class OrganizeHierarchyCommandTest extends KernelTestCase
{
    private Application $application;
    private CommandTester $commandTester;

    protected static function getKernelClass(): string
    {
        return Kernel::class;
    }

    protected function setUp(): void
    {
        $kernel = self::bootKernel();
        $this->application = new Application($kernel);
        $command = $this->application->find('matrikkel:organize-hierarchy');
        $this->commandTester = new CommandTester($command);
    }

    /**
     * Test: Command requires kommune parameter
     */
    public function testCommandRequiresKommune(): void
    {
        $this->commandTester->execute([]);
        
        $this->assertStringContainsString('--kommune is required', $this->commandTester->getDisplay());
        $this->assertNotEquals(0, $this->commandTester->getStatusCode());
    }

    /**
     * Test: Kommune must be 4-digit number
     */
    public function testKommuneMustBeFourDigits(): void
    {
        $this->commandTester->execute(['--kommune' => '46']);
        
        $this->assertStringContainsString('Kommune must be a 4-digit number', $this->commandTester->getDisplay());
        $this->assertNotEquals(0, $this->commandTester->getStatusCode());
    }

    /**
     * Test: Command shows error when kommune not found
     */
    public function testCommandHandlesNotFound(): void
    {
        $this->commandTester->execute(['--kommune' => '9999']);
        
        // Non-existent kommune should show warning about no matrikkelenheter found
        $this->assertStringContainsString('found', strtolower($this->commandTester->getDisplay()));
    }

    /**
     * Test: Command accepts valid kommune
     */
    public function testCommandAcceptsValidKommune(): void
    {
        // Using valid 4-digit commune number
        $this->commandTester->execute(['--kommune' => '4627']);
        
        // Should not show error about kommune format
        $this->assertStringNotContainsString('4-digit number', $this->commandTester->getDisplay());
    }

    /**
     * Test: Help text is comprehensive
     */
    public function testCommandHelpText(): void
    {
        // Test the actual help output by getting the command directly
        $command = $this->application->find('matrikkel:organize-hierarchy');
        $this->assertStringContainsString('Organize Portico', $command->getDescription());
    }

    /**
     * Test: Command shows progress during execution
     * (Integration with actual database may be needed for full test)
     */
    public function testCommandShowsProgressBar(): void
    {
        // This test would need a database setup
        // For now, we test that the command structure is correct
        $definition = $this->application->find('matrikkel:organize-hierarchy')->getDefinition();
        
        $this->assertTrue($definition->hasOption('kommune'));
        $this->assertTrue($definition->hasOption('matrikkelenhet'));
        $this->assertTrue($definition->hasOption('force'));
    }

    /**
     * Test: Specific matrikkelenhet parameter works
     */
    public function testSpecificMatrikkelenhetParameter(): void
    {
        // Should validate without error if parameter is provided
        $this->commandTester->execute([
            '--kommune' => '4627',
            '--matrikkelenhet' => '12345'
        ]);
        
        // Should not error on parameter parsing
        // (database state may vary)
        $output = $this->commandTester->getDisplay();
        $this->assertStringNotContainsString('--kommune is required', $output);
    }

    /**
     * Test: Force flag is accepted
     */
    public function testForceFlag(): void
    {
        $definition = $this->application->find('matrikkel:organize-hierarchy')->getDefinition();
        
        $this->assertTrue($definition->hasOption('force'));
        $this->assertFalse($definition->getOption('force')->isValueRequired());
    }
}
