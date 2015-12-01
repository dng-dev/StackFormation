<?php

namespace StackFormation;

class StackManager
{

    protected $parametersCache = [];
    protected $outputsCache = [];
    protected $resourcesCache = [];
    protected $tagsCache = [];

    protected $config;
    
    public function __construct()
    {
        $this->config = new Config();
    }

    /**
     * @return \Aws\CloudFormation\CloudFormationClient
     */
    protected function getCfnClient() {
        return SdkFactory::getCfnClient();
    }

    /**
     * Get parameter values for stack
     *
     * @param $stackName
     * @param null $key
     * @return mixed
     * @throws \Exception
     */
    public function getParameters($stackName, $key = null)
    {
        if (!isset($this->parametersCache[$stackName])) {
            $res = $this->getCfnClient()->describeStacks([
                'StackName' => $stackName,
            ]);
            $parameters = [];
            foreach ($res->search('Stacks[0].Parameters') as $parameter) {
                $parameters[$parameter['ParameterKey']] = $parameter['ParameterValue'];
            }
            $this->parametersCache[$stackName] = $parameters;
        }
        if (!is_null($key)) {
            if (!isset($this->parametersCache[$stackName][$key])) {
                throw new \Exception("Key '$key' not found");
            }
            return $this->parametersCache[$stackName][$key];
        }
        return $this->parametersCache[$stackName];
    }

    /**
     * Get output values for stack
     *
     * @param $stackName
     * @param null $key
     * @return mixed
     * @throws \Exception
     */
    public function getOutputs($stackName, $key = null)
    {
        if (!isset($this->outputsCache[$stackName])) {
            $res = $this->getCfnClient()->describeStacks([
                'StackName' => $stackName,
            ]);
            $outputs = [];
            $res = $res->search('Stacks[0].Outputs');
            if (is_array($res)) {
                foreach ($res as $output) {
                    $outputs[$output['OutputKey']] = $output['OutputValue'];
                }
            }
            $this->outputsCache[$stackName] = $outputs;
        }
        if (!is_null($key)) {
            if (!isset($this->outputsCache[$stackName][$key])) {
                throw new \Exception("Key '$key' not found");
            }
            return $this->outputsCache[$stackName][$key];
        }
        return $this->outputsCache[$stackName];
    }

    /**
     * Get output values for stack
     *
     * @param $stackName
     * @param null $key
     * @return mixed
     * @throws \Exception
     */
    public function getTags($stackName, $key = null)
    {
        if (!isset($this->tagsCache[$stackName])) {
            $res = $this->getCfnClient()->describeStacks([
                'StackName' => $stackName,
            ]);
            $outputs = [];
            $res = $res->search('Stacks[0].Tags');
            if (is_array($res)) {
                foreach ($res as $output) {
                    $outputs[$output['Key']] = $output['Value'];
                }
            }
            $this->tagsCache[$stackName] = $outputs;
        }
        if (!is_null($key)) {
            if (!isset($this->tagsCache[$stackName][$key])) {
                throw new \Exception("Key '$key' not found");
            }
            return $this->tagsCache[$stackName][$key];
        }
        return $this->tagsCache[$stackName];
    }

    /**
     * Get output values for stack
     *
     * @param $stackName
     * @param null $LogicalResourceId
     * @return mixed
     * @throws \Exception
     */
    public function getResources($stackName, $LogicalResourceId = null)
    {
        if (!isset($this->resourcesCache[$stackName])) {

            $res = $this->getCfnClient()->describeStackResources([
                'StackName' => $stackName,
            ]);
            $resources = [];
            foreach ($res->search('StackResources[]') as $resource) {
                $resources[$resource['LogicalResourceId']] = $resource['PhysicalResourceId'];
            }
            $this->resourcesCache[$stackName] = $resources;
        }
        if (!is_null($LogicalResourceId)) {
            if (!isset($this->resourcesCache[$stackName][$LogicalResourceId])) {
                throw new \Exception("LogicalResourceId '$LogicalResourceId' not found");
            }
            return $this->resourcesCache[$stackName][$LogicalResourceId];
        }
        return $this->resourcesCache[$stackName];
    }

    /**
     * @return array
     */
    public function getStacksFromApi() {
        $res = $this->getCfnClient()->listStacks([
            'StackStatusFilter' => explode('|', 'CREATE_IN_PROGRESS|CREATE_FAILED|CREATE_COMPLETE|ROLLBACK_IN_PROGRESS|ROLLBACK_FAILED|ROLLBACK_COMPLETE|DELETE_IN_PROGRESS|DELETE_FAILED|UPDATE_IN_PROGRESS|UPDATE_COMPLETE_CLEANUP_IN_PROGRESS|UPDATE_COMPLETE|UPDATE_ROLLBACK_IN_PROGRESS|UPDATE_ROLLBACK_FAILED|UPDATE_ROLLBACK_COMPLETE_CLEANUP_IN_PROGRESS|UPDATE_ROLLBACK_COMPLETE')
        ]);
        $stacks = [];
        foreach ($res->search('StackSummaries[]') as $stack) {
            $stacks[$stack['StackName']] = [ 'Status' => $stack['StackStatus']];
        }
        return $stacks;
    }

    public function deleteStack($stackName) {
        $res = $this->getCfnClient()->deleteStack([
            'StackName' => $stackName,
        ]);
    }

    public function getPreprocessedTemplate($stackName) {
        $stackConfig = $this->config->getStackConfig($stackName);

        $template = $stackConfig['template'];
        if (empty($template)) {
            throw new \Exception('No template found');
        }

        if (!is_file($template)) {
            throw new \Exception("Template file '$template' not found.");
        }
        $preProcessor = new Preprocessor();
        return $preProcessor->process($template);
    }

    public function getTemplate($stackName) {
        $res = $this->getCfnClient()->getTemplate([ 'StackName' => $stackName]);
        return $res->get("TemplateBody");
    }

    /**
     * Update stack
     *
     * @param $stackName
     * @throws \Exception
     */
    public function deployStack($stackName, $onFailure='ROLLBACK')
    {
        if (!in_array($onFailure, ['ROLLBACK', 'DO_NOTHING', 'DELETE'])) {
            throw new \InvalidArgumentException("Invalid value for onFailure parameter");
        }

        $effectiveStackName = $this->config->getEffectiveStackName($stackName);

        $arguments = [
            'Capabilities' => ['CAPABILITY_IAM'],
            'StackName' => $effectiveStackName,
            'Parameters' => $this->getParametersFromConfig($stackName),
            'TemplateBody' => $this->getPreprocessedTemplate($stackName)
        ];

        $stackStatus = $this->getStackStatus($effectiveStackName);
        if (strpos($stackName, 'IN_PROGRESS') !== false) {
            throw new \Exception("Stack can't be updated right now. Status: $stackStatus");
        } elseif (!empty($stackStatus) && $stackStatus != 'DELETE_COMPLETE') {
            $this->getCfnClient()->updateStack($arguments);
        } else {
            $arguments['Tags'] = $this->config->getStackTags($stackName);
            $arguments['OnFailure'] = $onFailure;
            $this->getCfnClient()->createStack($arguments);
        }

    }

    public function observeStackActivity(
        $stackName,
        \Symfony\Component\Console\Output\OutputInterface $output,
        $pollInterval=10)
    {
        $printedEvents = [];
        $first = true;
        do {
            if ($first) {
                $first = false;
            } else {
                sleep($pollInterval);
            }
            $status = $this->getStackStatus($stackName);

            $output->writeln("-> Polling... (Status: $status)");

            $events = $this->describeStackEvents($stackName);

            $rows = [];
            foreach ($events as $eventId => $event) {
                if (!in_array($eventId, $printedEvents)) {
                    $printedEvents[] = $eventId;
                    $rows[] = [
                        // $event['Timestamp'],
                        $this->decorateStatus($event['Status']),
                        $event['ResourceType'],
                        $event['LogicalResourceId'],
                        wordwrap($event['ResourceStatusReason'], 40, "\n")
                    ];
                }
            }

            $table = new \Symfony\Component\Console\Helper\Table($output);
            $table->setRows($rows);
            $renderedTable = $table->render();
            $output->write($renderedTable);

        } while (strpos($status, 'IN_PROGRESS') !== false);

        $formatter = new \Symfony\Component\Console\Helper\FormatterHelper();
        if (strpos($status, 'FAILED') !== false) {
            $formattedBlock = $formatter->formatBlock(['Error!', 'Status: ' . $status], 'error');
        } else {
            $formattedBlock = $formatter->formatBlock(['Completed', 'Status: ' . $status], 'info');
        }
        $output->writeln($formattedBlock);

        // TODO: make this a table
        $outputs = $this->getOutputs($stackName);
        if (is_array($outputs)) {
            foreach ($outputs as $key => $value) {
                printf("%30s: %s\n", $key, $value);
            }
        }
    }

    protected function decorateStatus($status) {
        if (strpos($status, 'IN_PROGRESS') !== false) {
            return "<fg=yellow>$status</>";
        }
        if (strpos($status, 'COMPLETE') !== false) {
            return "<fg=green>$status</>";
        }
        if (strpos($status, 'FAILED') !== false) {
            return "<fg=red>$status</>";
        }
        return $status;
    }

    public function getStackStatus($stackName) {
        $stacksFromApi = $this->getStacksFromApi();
        if (isset($stacksFromApi[$stackName])) {
            return $stacksFromApi[$stackName]['Status'];
        }
        return null;
    }

    public function describeStackEvents($stackName) {
        $res = $this->getCfnClient()->describeStackEvents([
            'StackName' => $stackName,
        ]);
        $events = [];
        foreach ($res->search('StackEvents[]') as $event) {
            $events[$event['EventId']] = [
                'Timestamp' => (string)$event['Timestamp'],
                'Status' => $event['ResourceStatus'],
                'ResourceType' => $event['ResourceType'],
                'LogicalResourceId' => $event['LogicalResourceId'],
                'ResourceStatusReason' => isset($event['ResourceStatusReason']) ? $event['ResourceStatusReason'] : ''
            ];
        }
        return array_reverse($events, true);
    }

    public function resolvePlaceholders($string) {

        // {env:...}
        $string = preg_replace_callback('/\{env:(.*?)\}/', function($matches) {
            if (!getenv($matches[1])) {
                throw new \Exception("Environment variable '{$matches[1]}' not found");
            }
            return getenv($matches[1]);
        }, $string);

        // {output:...:...}
        $string = preg_replace_callback('/\{output:(.*?):(.*?)\}/', function($matches) {
            return $this->getOutputs($matches[1], $matches[2]);
        }, $string);

        // {resource:...:...}
        $string = preg_replace_callback('/\{resource:(.*?):(.*?)\}/', function($matches) {
            return $this->getResources($matches[1], $matches[2]);
        }, $string);

        // {parameter:...:...}
        $string = preg_replace_callback('/\{parameter:(.*?):(.*?)\}/', function($matches) {
            return $this->getParameters($matches[1], $matches[2]);
        }, $string);

        return $string;
    }

    public function getParametersFromConfig($stackName) {

        $stackConfig = $this->config->getStackConfig($stackName);

        $parameters = [];

        if (isset($stackConfig['parameters'])) {
            foreach ($stackConfig['parameters'] as $parameterKey => $parameterValue) {
                $tmp = ['ParameterKey' => $parameterKey];
                if (is_null($parameterValue)) {
                    $tmp['UsePreviousValue'] = true;
                } else {
                    $tmp['ParameterValue'] = $this->resolvePlaceholders($parameterValue);
                }
                $parameters[] = $tmp;
            }
        }
        return $parameters;
    }

    public function getConfig() {
        return $this->config;
    }

}