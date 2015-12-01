<?php

namespace StackFormation\Command;

use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class TemplateDiffCommand extends AbstractCommand
{

    protected function configure()
    {
        $this
            ->setName('stack:diff')
            ->setDescription('Compare the local copy with the current live stack')
            ->addArgument(
                'stack',
                InputArgument::REQUIRED,
                'Stack'
            );
    }

    protected function interact(InputInterface $input, OutputInterface $output)
    {
        $this->interact_askForConfigStack($input, $output);
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $stack = $input->getArgument('stack');

        $effectiveStackName = $this->config->getEffectiveStackName($stack);
        $live = trim($this->stackManager->getTemplate($effectiveStackName));
        $file_live = tempnam(sys_get_temp_dir(), 'sfn_live_');
        file_put_contents($file_live, $live);

        $local = trim($this->stackManager->getPreprocessedTemplate($stack));
        $file_local = tempnam(sys_get_temp_dir(), 'sfn_local_');
        file_put_contents($file_local, $local);

        $command = is_file('/usr/bin/colordiff') ? 'colordiff' : 'diff';
        $command .= " -u $file_live $file_local";

        passthru($command);

        unlink($file_live);
        unlink($file_local);
    }

}