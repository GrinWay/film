<?php

namespace App\Command;

use Symfony\Component\Console\Command\LockableTrait;
use Symfony\Component\String\Slugger\SluggerInterface;
use Carbon\Carbon;
use Symfony\Component\Uid\Uuid;

use function Symfony\Component\String\u;

use Symfony\Component\Finder\SplFileInfo;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Filesystem\{
    Path,
    Filesystem
};
use Psr\Container\ContainerInterface;
use Symfony\Contracts\Service\ServiceSubscriberInterface;
use Symfony\Component\Console\Command\SignalableCommandInterface;
use Symfony\Component\Console\Helper\TableStyle;
use Symfony\Component\Console\Helper\{
    ProgressBar,
    Table
};
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Validator\{
    Constraints,
    Validation
};
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Helper\{
    TableSeparator
};
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Console\Completion\{
    CompletionSuggestions,
    CompletionInput
};
use Symfony\Component\Console\Formatter\OutputFormatterStyle;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Attribute\{
    AsCommand
};
use Symfony\Component\Console\Input\{
    InputArgument,
    InputOption,
    InputInterface
};
use Symfony\Component\Console\Output\{
    OutputInterface
};
use App\Service\ArrayService;
use App\Service\StringService;
use App\Service\RegexService;
use GrinWay\Service\Service\OSService;
use GrinWay\Command\Contracts\IO as GrinWayCommandDumper;

/*
*/
#[AsCommand(
    name: 'join',
)]
class JoinCommand extends AbstractCommand
{
    public const WIN_KEY = 'windows';
    public const DESCRIPTION = 'film.join_description';

    public const INPUT_AUDIO_FIND_DEPTH = ['>= 0', '<= 1'];

    /*
        [
            0 => [
                'inputVideoFilename'        => '<string>',
                'inputAudioFilename'        => '<string>',
                'outputVideoFilename'       => '<string>',
            ]
            ...
        ]
    */
    private array $commandParts      = [];
    private ?string $fromRoot        = null;
    private ?string $toDirname       = null;
    private int $allVideosCount      = 0;
    private int $countVideoWithAudio = 0;
    private readonly string $supportedFfmpegVideoFormats;
    private readonly string $supportedFfmpegAudioFormats;
    private readonly array $arrayOfSupportedFfmpegVideoFormatsRegex;
    private readonly array $arrayOfSupportedFfmpegVideoFormats;
    private ?string $firstInputVideoExt = null;

    //###> DEFAULT ###

    public function __construct(
        $devLogger,
        $t,
        $progressBarSpin,
        //
        private readonly ArrayService $arrayService,
        private readonly StringService $stringService,
        private readonly RegexService $regexService,
        private readonly SluggerInterface $slugger,
        private readonly Filesystem $filesystem,
        string $supportedFfmpegVideoFormats,
        string $supportedFfmpegAudioFormats,
        private readonly string $ffmpegAbsPath,
        private readonly string $joinTitle,
        private readonly string $endTitle,
        private readonly string $figletAbsPath,
        private readonly string $ffmpegAlgorithmForInputVideo,
        private readonly string $ffmpegAlgorithmForInputAudio,
        private readonly string $ffmpegAlgorithmForOutputVideo,
        private string $endmarkOutputVideoFilename,
        private $grinWayServiceCarbonFactory,
        private readonly OSService $osService,
        private readonly bool $showFilmHelpInfo,
    ) {
        parent::__construct(
            devLogger:          $devLogger,
            t:                  $t,
            progressBarSpin:    $progressBarSpin,
        );

        //###>
        $this->endmarkOutputVideoFilename = \preg_replace(
            '~[<>:"/\\\?*\|]~',
            '',
            $endmarkOutputVideoFilename,
        );

        //###>
        $normalizeFormats = static function (
            string $supportedFormats,
        ): string {
            return \preg_replace(
                '~[^a-zа-я0-9\|]~iu',
                '',
                $supportedFormats,
            );
        };
        $this->supportedFfmpegVideoFormats = ''
            . '[.](?:'
            . $normalizeFormats($supportedFfmpegVideoFormats)
            . ')'
        ;
        $this->supportedFfmpegAudioFormats = ''
            . '[.](?i)(?:'
            . $normalizeFormats($supportedFfmpegAudioFormats)
            . ')'
        ;
        $this->arrayOfSupportedFfmpegVideoFormats = \array_values(\array_unique(\array_map(
            static fn($v): string => \mb_strtolower((string) $v),
            \array_filter(
                \explode(
                    '|',
                    $normalizeFormats($supportedFfmpegVideoFormats),
                ),
                static fn($v): bool => !empty($v),
            ),
        )));
        $this->arrayOfSupportedFfmpegAudioFormats = \array_values(\array_unique(\array_map(
            static fn($v): string => \mb_strtolower((string) $v),
            \array_filter(
                \explode(
                    '|',
                    $normalizeFormats($supportedFfmpegAudioFormats),
                ),
                static fn($v): bool => !empty($v),
            ),
        )));
        $this->arrayOfSupportedFfmpegVideoFormatsRegex = \array_map(
            static fn($v): string => '~^.*[.](?i)(?:' . $v . ')$~u',
            $this->arrayOfSupportedFfmpegVideoFormats,
        );
        //###<
    }


    //###> ABSTRACT REALIZATION ###

    /* AbstractCommand */
    protected function getExitCuzLockMessage(): string
    {
        return ''
            . $this->t->trans('grin_way_command.command_word')
            . ' ' . '"' . $this->getName() . '"'
            . ' ' . 'может быть запущена только по одному пути за раз!'
        ;
    }

	/* AbstractCommand */
    protected function initialize(
        InputInterface $input,
        OutputInterface $output,
    ) {
        parent::initialize($input, $output);

        $this->fromRoot = $this->getRoot();
    }

	/* AbstractCommand */
    protected function execute(
        InputInterface $input,
        OutputInterface $output,
    ) {
        if ($this->showFilmHelpInfo) {
			$this->dumpHelpInfo($output);
		}
		
        return parent::execute(
            $input,
            $output,
        );
    }

    protected function command(
        InputInterface $input,
        OutputInterface $output,
    ): int {
        $this->fillInCommandParts();

        $this->dumpCommandParts($output);

        $this->isOk(exitWhenDisagree: true);

        $this->ffmpegExec($output);

        $this->getIo()->success(
			$this->t->trans($this->endTitle),
		);

        return Command::SUCCESS;
    }

    protected function getLockName(): string
    {
        return $this->getRoot();
    }

    //###< ABSTRACT REALIZATION ###


    //###> CAN OVERRIDE ###

	/*
		return the \php_uname(mode: "s") of your OS if you can execute this file
		return null if the OS is not appropriate
	*/
    protected function getOsNameByFileExt(
        string $path,
    ): ?string {
        $isWinExt = \str_ends_with(\strtolower($path), 'exe');

		if ($isWinExt) {
            return self::WIN_KEY;
        }

        return null;
    }

    //###< CAN OVERRIDE ###


    //###> HELPER ###

    private function assignNonExistentToDirname(): void
    {
        /* more safe
        $newDirname = (string) $this->slugger->slug(
            (string) $this->grinWayServiceCarbonFactory->make(new \DateTime)
        );
        */
        $newDirname = \str_replace(':', '_', (string) $this->grinWayServiceCarbonFactory->make(new \DateTime()));

        while (\is_dir($newDirname)) {
            $newDirname = (string) $this->slugger->slug(Uuid::v1());
        }
        $this->toDirname    = $newDirname;
    }

    private function dumpHelpInfo(
        OutputInterface $output,
    ): void {
		$this
			->ioDump(
				$this->t->trans('###> СПРАВКА ###'),
				new GrinWayCommandDumper\SectionIODumper(),
			)
			->ioDump(
				$this->t->trans('Открывай консоль в месте расположения видео.'),
				new GrinWayCommandDumper\FormattedIODumper('<bg=black;fg=yellow>%s</>'),
				afterDumpNewLines: 1,
			)
			->ioDump(
				[
					$this->t->trans('Для того чтобы к видео был найден нужный аудио файл:'),
					$this->t->trans('1) Аудио файл должен быть назван в точности как видео файл (расширение не учитывается)'),
					$this->t->trans('2) Аудио файл должен находится во вложенности не более 1 папки относительно видео'),
				],
				new GrinWayCommandDumper\FormattedIODumper('<bg=black;fg=green> %s</>', afterDumpNewLines: 1),
			)
			->ioDump(
				$this->t->trans('Для объединённых видео файлов создаётся новая, гарантированно уникальная папка.'),
				new GrinWayCommandDumper\FormattedIODumper('<bg=black;fg=yellow>%s</>'),
				afterDumpNewLines: 1,
			)
			->ioDump(
				[
					$this->t->trans('Программа объёдиняет видео с аудио в новый видео файл'),
					$this->t->trans('Исходные видео и аудио остаются прежними как есть (не изменяются)'),
				],
				new GrinWayCommandDumper\FormattedIODumper('<bg=black;fg=green> %s</>', afterDumpNewLines: 1),
			)
			->ioDump(
				$this->t->trans('###< СПРАВКА ###'),
				new GrinWayCommandDumper\SectionIODumper(),
			)
		;
    }

    private function ffmpegExec(
        OutputInterface $output,
    ): void {
        if (empty($this->commandParts) || $this->toDirname === null) {
            $this->getIo()->error($this->t->trans('ERROR'));
            return;
        }

        $this->filesystem->mkdir($this->stringService->getPath($this->getRoot(), $this->toDirname));

        $resultsFilenames = [];
		$currentVideoNumber = 0;
        \array_walk(
			$this->commandParts,
			function (&$commandPart) use (
				&$resultsFilenames,
				&$output,
				&$currentVideoNumber,
			) {
				[
					'inputVideoFilename'        => $inputVideoFilename,
					'inputAudioFilename'        => $inputAudioFilename,
					'outputVideoFilename'       => $outputVideoFilename,
				] = $commandPart;
				
				$relativeOutputVideoFilename = $this->makePathRelative($outputVideoFilename);

				// ffmpeg algorithm
				$ffmpegAbsPath = Path::normalize($this->ffmpegAbsPath);
				$command    = '"' . $this->stringService->getPath($ffmpegAbsPath) . '"'
					. (string) u($this->ffmpegAlgorithmForInputVideo)->ensureEnd(' ')->ensureStart(' ')
					. '"' . $inputVideoFilename . '"'
					. (string) u($this->ffmpegAlgorithmForInputAudio)->ensureEnd(' ')->ensureStart(' ')
					. '"' . $inputAudioFilename . '"'
					. (string) u($this->ffmpegAlgorithmForOutputVideo)->ensureEnd(' ')->ensureStart(' ')
					. '"' . $outputVideoFilename . '"'
				;
				
				//###>
				$getOsNameByFileExt = $this->getOsNameByFileExt(...);
				$filesystemRemoveFunc = $this->filesystem->remove(...);
				$shutdown = $this->shutdown(...);
				$this->osService->setCallback(
					static fn() => $getOsNameByFileExt($ffmpegAbsPath),
					'ffmpeg',
					static function () use (
						$command,
						$outputVideoFilename,
						&$filesystemRemoveFunc,
						&$shutdown,
					) {
						$result_code = null;
						try {
							\exec($command, result_code: $result_code);						
						} finally {
							if ($result_code !== 0) {
								if (\is_file($outputVideoFilename)) {
									$filesystemRemoveFunc($outputVideoFilename);
								}
								$shutdown();
							}						
						}
						return true;
					},
				);
				
				// DUMP INFO DURING THE PROCESS
				$partOfTheProcess = '[' . ++$currentVideoNumber . ' / ' . $this->countVideoWithAudio . ']';
				$nameFormat = "<bg=black;fg=white;options=bold>%s</>";
				$numberFormat = "<bg=white;fg=black>%s</>";
				$this->getCloneTable()
					->setHeaders(
						[
							\sprintf($nameFormat, $relativeOutputVideoFilename),
							\sprintf($numberFormat, $partOfTheProcess),
						],
					)
					->setHorizontal(false)
					->render()
				;
				
				$wasMade = ($this->osService)(
					callbackKey: 'ffmpeg',
					removeCallbackAfterExecution: true,
				);

				if ($wasMade !== true) {
					$this->exit(
						$this->t->trans('error.os.ffmpeg', ['%ffmpeg%' => $ffmpegAbsPath]),
						style: 'error',
					);
					return;
				}

				// dump
				$this->getIo()->warning([
					$relativeOutputVideoFilename . (string) u('(' . $this->t->trans('ready') . ')')->ensureStart(' '),
				]);

				$resultsFilenames [] = $relativeOutputVideoFilename;
			}
		);

        if (\count($resultsFilenames) > 1) {
            $this->getIo()->info([
                $this->t->trans('ИТОГ:'),
                ...$resultsFilenames,
            ]);
        }
    }

    private function fillInCommandParts(): void
    {
        $this->assignNonExistentToDirname();

        $finderInputVideoFilenames = (new Finder())
            ->in($this->fromRoot)
			->sort($this->humanSort(...))
			->files()
            ->ignoreUnreadableDirs()
            ->depth(0)
            ->name($this->arrayOfSupportedFfmpegVideoFormatsRegex)
        ;
		
        $arrayInputVideos = \iterator_to_array($finderInputVideoFilenames, false);
		if (isset($arrayInputVideos[0])) {
            $firstInputVideoExt = $arrayInputVideos[0]->getExtension();
            if ($this->firstInputVideoExt === null) {
                $this->firstInputVideoExt = $firstInputVideoExt;
            }
        }

        foreach ($finderInputVideoFilenames as $finderInputVideoFilename) {
            ++$this->allVideosCount;

            $inputAudioFilename     = $this->getInputAudioFilename($finderInputVideoFilename);
            if ($inputAudioFilename === null) {
                continue;
            }

            $inputVideoFilename     = $finderInputVideoFilename->getFilename();

            $outputVideoFilename    = ''

                . $finderInputVideoFilename->getFilenameWithoutExtension()
                . $this->endmarkOutputVideoFilename
                // OR INSTEAD, JUST ENSURE
                //. (string) u($finderInputVideoFilename->getFilenameWithoutExtension())->ensureEnd($this->endmarkOutputVideoFilename)

                . '.'
                . $finderInputVideoFilename->getExtension()
            ;

            $this->commandParts     [] =
            [
                'inputVideoFilename'        => $this->stringService->getPath($this->fromRoot, $inputVideoFilename),
                'inputAudioFilename'        => $this->stringService->getPath($this->fromRoot, $inputAudioFilename),
                'outputVideoFilename'       => $this->stringService->getPath($this->fromRoot, $this->toDirname, $outputVideoFilename),
            ];
			++$this->countVideoWithAudio;
        }
    }

    private function dumpCommandParts(
        OutputInterface $output,
    ): void {
        if (empty($this->commandParts)) {
            $this->getIo()->success(
                $this->t->trans('Нечего соединять')
            );
            exit();
        }

        $this->beautyDump($output);

        $infos = [
            $this->t->trans('Видео') . ':',
            $this->t->trans('Аудио') . ':',
            $this->t->trans('Результат') . ':',
        ];

        foreach (
            $this->commandParts as [
			'inputVideoFilename' => $inputVideoFilename,
			'inputAudioFilename' => $inputAudioFilename,
			'outputVideoFilename' => $outputVideoFilename,
		]) {
            $inputVideoFilename = $this->makePathRelative($inputVideoFilename);
            $inputAudioFilename = $this->makePathRelative($inputAudioFilename);
            $outputVideoFilename = $this->makePathRelative($outputVideoFilename);

			$inputVideoFormat = '<bg=yellow;fg=black%s>';
			$inputAudioFormat = '<bg=white;fg=black%s>';
			$outputVideoFormat = '<bg=green;fg=black%s>';
			
			$this->getCloneTable()
				->setHeaders([
					\sprintf($inputVideoFormat, ';options=reverse') . $infos[0] . '</>',
					\sprintf($inputAudioFormat, ';options=reverse') . $infos[1] . '</>',
					\sprintf($outputVideoFormat, ';options=reverse') . $infos[2] . '</>',
				])
				->setRows([
					[
						'"' . \sprintf($inputVideoFormat, '') . $inputVideoFilename . '</>"',
						'"' . \sprintf($inputAudioFormat, '') . $inputAudioFilename . '</>"',
						'"' . \sprintf($outputVideoFormat, '') . $outputVideoFilename . '</>"',
					],
				])
				->render()
			;
        }

        $sumUpStrings = [
            $this->t->trans('Всего видео:'),
            $this->t->trans('Видео с переводами:'),
        ];

		//###>
		$allFoundVideos = $this->allVideosCount;
		
		$videosWithAudio = \count($this->commandParts);
		$videoWithAudioFormat = '<bg=black;fg=white%s>';
		$videoWithAudioDopFormat = '';
		
		if ($allFoundVideos != $videosWithAudio) {
			$videoWithAudioDopFormat = ';options=underscore,bold';
		}
		
		$this->getCloneTable()
			->setHeaders([
				$sumUpStrings[0],
				\sprintf($videoWithAudioFormat, $videoWithAudioDopFormat) . $sumUpStrings[1] . '</>',
			])
			->setRows([
				[
					$allFoundVideos,
					\sprintf($videoWithAudioFormat, $videoWithAudioDopFormat) . $videosWithAudio . '</>',
				],
			])
			->render()
		;
		
		/*
		$output->writeln(
            '<bg=black;fg=yellow>'
            . \str_pad(
                $sumUpStrings[0],
                $this->stringService->getOptimalWidthForStrPad($sumUpStrings[0], $sumUpStrings)
            ) . $this->allVideosCount . '</>'
        );
        $output->writeln(
            '<bg=black;fg=yellow>'
            . \str_pad(
                $sumUpStrings[1],
                $this->stringService->getOptimalWidthForStrPad($sumUpStrings[1], $sumUpStrings)
            ) . $videosWithAudio = \count($this->commandParts) . '</>'
        );
        $output->writeln('');
		*/
    }

    private function beautyDump(
        OutputInterface $output,
    ): void {
        $figletAbsPath = $this->figletAbsPath;
        $figletRoot = \dirname($figletAbsPath);
        
		$command = ''
            . ' cd "' . $figletRoot . '" &&'

            . ' ' . '"' . $figletAbsPath . '"'

            // font: without .ext
            //. ' ' . '-f "' . $this->stringService->getPath($figletRoot, 'fonts/Moscow') . '"'
            . ' ' . '-f "' . $this->stringService->getPath($figletRoot, 'fonts/3d_diagonal') . '"'

            . ' ' . '-c'
            . ' ' . ' -- "' . $this->joinTitle . '"'
        ;
        
		$getOsNameByFileExt = $this->getOsNameByFileExt(...);
        $this->osService
            ->setCallback(
                static fn() => $getOsNameByFileExt($figletAbsPath),
                'figlet',
                static fn() => \system($command),
            )
        ;
        ($this->osService)(
            callbackKey: 'figlet',
            removeCallbackAfterExecution: true,
        );
    }

    private function getInputAudioFilename(
        SplFileInfo $finderInputVideoFilename,
    ): ?string {
        $inputAudioFilename = null;
        $inputVideoFilenameWithoutExtension = $finderInputVideoFilename->getFilenameWithoutExtension();
        $inputVideoFilenameWithExtension = $finderInputVideoFilename->getRelativePathname();

        //###> SORT (LESS DEEP THAN FIRST)
        $shorterFirst = static fn($l, $r): bool => (
            \mb_strlen($l->getRelativePathname()) > \mb_strlen($r->getRelativePathname())
        );

        $finderInputAudioFilenames = (new Finder())
            ->in($this->fromRoot)
            ->files()
            ->sort($shorterFirst)
            ->ignoreUnreadableDirs()
            ->depth(self::INPUT_AUDIO_FIND_DEPTH)
            ->name(
                $regex = '~^'
                    . $this->regexService->getEscapedStrings($inputVideoFilenameWithoutExtension)
                    . $this->supportedFfmpegAudioFormats
                    . '$~'
            )
        ;
		
		// EXCLUDE THE SAME VIDEO FILE
        if ($this->firstInputVideoExt !== null) {
            $audioNotRelNameRegex = '~^'
                . $this->regexService->getEscapedStrings(
                    $finderInputVideoFilename->getRelativePathname(),
                )
                . '$~u'
            ;
            $finderInputAudioFilenames->notPath($audioNotRelNameRegex);
        }

        $inputAudioFilenames = \array_values(
            \array_map(
                static fn($v) => $v->getRelativePathname(),
                \iterator_to_array($finderInputAudioFilenames),
            )
        );
		
        while (isset($inputAudioFilenames[0])) {
            $zeroInputAudioFilename = $inputAudioFilenames[0];

            if ($zeroInputAudioFilename != $inputVideoFilenameWithExtension) {
                $inputAudioFilename = $zeroInputAudioFilename;
                break;
            }
            \array_shift($inputAudioFilenames);
        }
		

        return $inputAudioFilename;
    }

    private function getRoot(): string
    {
        return Path::normalize(\getcwd());
    }

    private function makePathRelative(string $needyPath): string
    {
        return \rtrim($this->filesystem->makePathRelative($needyPath, $this->fromRoot), '/');
    }

    private function humanSort(
        SplFileInfo $l,
        SplFileInfo $r,
    ): bool {
        $LName = $this->stringService->getPath(
			$l->getRelativePath(),
			$l->getFilenameWithoutExtension(),
		);
        $RName = $this->stringService->getPath(
			$r->getRelativePath(),
			$r->getFilenameWithoutExtension(),
		);

		$regex = '~[^0-9]+~';
		$LNumber = (int) \preg_replace($regex, '', $LName);
		$RNumber = (int) \preg_replace($regex, '', $RName);

		return $LNumber > $RNumber;
    }

    private function extSort(
        SplFileInfo $l,
        SplFileInfo $r,
        array $supported,
    ): bool {
        $supported = \array_flip($supported);

        $Lkey = \mb_strtolower($l->getExtension());
        $Rkey = \mb_strtolower($r->getExtension());

        if (
            false
            || !isset($supported[$Lkey])
            || !isset($supported[$Rkey])
        ) {
            return false;
        }

        return $supported[$Lkey] > $supported[$Rkey];
    }
}
