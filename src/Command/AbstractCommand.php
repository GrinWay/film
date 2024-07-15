<?php

namespace App\Command;

use GrinWay\Command\Command\AbstractCommand as GrinWayAbstractCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Helper\{
    ProgressBar,
    FormatterHelper,
    Table,
    TableStyle,
    TableSeparator
};

abstract class AbstractCommand extends GrinWayAbstractCommand
{
    public const DESCRIPTION = '!CHANGE ME!';


    //###> ABSTRACT REALIZATION ###

    /* AbstractCommand */
    protected static function getCommandDescription(): string
    {
        return static::DESCRIPTION;
    }

    /* AbstractCommand */
    protected static function getCommandHelp(): string
    {
        return static::DESCRIPTION;
    }

    //###< ABSTRACT REALIZATION ###
	
	
    //###> YOU CAN OVERRIDE IT ###

    /* AbstractCommand */
	protected function setTable(
        InputInterface $input,
        OutputInterface $output,
    ): void {
		//###> create table
        $this->table = new Table($output); //$this->io->createTable();
        $tableStyle = new TableStyle();

        //###> customize style
        $tableStyle
            ->setCellHeaderFormat('<fg=white;bg=black>%s</>')
            ->setHorizontalBorderChars(' ')
            ->setVerticalBorderChars('')
            ->setDefaultCrossingChar(' ')
        ;

        //###> set style
        $this->table
			->setHorizontal(true)
			->setStyle($tableStyle)
		;
    }

    //###< YOU CAN OVERRIDE IT ###
}
