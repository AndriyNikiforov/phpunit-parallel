<?php

namespace phpunit_parallel\ipc\WorkerTestExecutorTest;

use Phake;
use phpunit_parallel\TestDistributor;
use phpunit_parallel\ipc\WorkerProcess;
use phpunit_parallel\ipc\WorkerTestExecutor;
use phpunit_parallel\model\TestRequest;
use phpunit_parallel\model\TestResult;

class WorkerTestExecutorTest extends \PHPUnit_Framework_TestCase
{
    /** @var TestDistributor */
    private $distributor;
    /** @var WorkerProcess */
    private $process;
    /** @var WorkerTestExecutor */
    private $executor;

    public function setUp()
    {
        $this->distributor = Phake::mock(TestDistributor::class);
        $this->process = Phake::mock(WorkerProcess::class);
        Phake::when($this->process)->isRunning()->thenReturn(true);
        $this->executor = new WorkerTestExecutor(1, $this->distributor, $this->process);
    }

    public function testStartTestSendsNextTest()
    {
        $test = new TestRequest(1, 'a', 'b', 'c');
        $this->executor->run($test);

        Phake::verify($this->process)->write($test->encode());
    }

    public function testCompletingTestSendsResultToDistributor()
    {
        $request = new TestRequest(1, 'a', 'b', 'c');
        $result = TestResult::errorFromRequest($request, 'Testing');

        $this->executor->run($request);
        $this->executor->onTestResult($result);

        Phake::verify($this->distributor, Phake::times(1))->testCompleted($this->executor, $result);
        Phake::verify($this->distributor, Phake::times(1))->testCompleted(Phake::anyParameters());
    }

    public function testStdErrWhileExecutingTestSendsOnlyOneResult()
    {
        $request = new TestRequest(1, 'a', 'b', 'c');
        $result = TestResult::errorFromRequest($request, 'Testing');

        $this->executor->run($request);
        $this->executor->onStdErr("a message");
        $this->executor->onTestResult($result);

        Phake::verify($this->distributor, Phake::atMost(1))->testCompleted(Phake::anyParameters());

        $this->assertEquals("Testing", $result->getErrors()[0]->message);
        $this->assertEquals("STDERR: a message", $result->getErrors()[1]->message);
    }

    public function testProcessCrashWithActiveTest()
    {
        $request = new TestRequest(1, 'a', 'b', 'c');

        $this->executor->run($request);
        $this->executor->onStdErr("a message");
        $this->executor->onExit(1);

        Phake::verify($this->distributor, Phake::atMost(1))->testCompleted(Phake::anyParameters());
        Phake::verify($this->distributor)->testCompleted($this->executor, Phake::capture($response));

        $this->assertEquals(1, $response->getId());
        $this->assertEquals("Worker1 died\na message", $response->getErrors()[0]->message);
    }

    public function testProcessSendsUnexpectedResult()
    {
        $request1 = new TestRequest(1, 'foo', 'oo', 'footest.php');
        $request2 = new TestRequest(2, 'b', 'ar', 'bartest.php');

        $this->executor->run($request1);
        $this->executor->onTestResult(TestResult::errorFromRequest($request2, "Didn't bar!"));

        Phake::verify($this->distributor, Phake::atMost(1))->testCompleted($this->executor, Phake::capture($response));

        $this->assertEquals(2, $response->getId());
        $this->assertEquals("Didn't bar!", $response->getErrors()[0]->message);
        $this->assertEquals(
            "An unexpected test was run, this could be a naming issue:\n" .
            "  Expected #1 - foo::footest.php\n" .
            "  Got #2 - b::bartest.php\n",
            $response->getErrors()[1]->message
        );
    }
}
