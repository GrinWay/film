<?php

namespace App\Command;

use App\Service\RegexService;
use App\Service\StringService;
use Carbon\Carbon;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Cursor;
use Symfony\Component\Console\Helper\TableStyle;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Finder\SplFileInfo;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Symfony\Component\String\Slugger\SluggerInterface;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\Translation\TranslatorInterface;
use function Symfony\Component\String\u;

#[AsCommand(name: 'join')]
class JoinCommand extends AbstractCommand
{
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
    private array $commandParts = [];
    private ?string $toDirname = null;
    private int $allVideosCount = 0;
    private readonly string $supportedFfmpegAudioFormats;
    private readonly array $arrayOfSupportedFfmpegVideoFormatsRegex;
    private readonly array $arrayOfSupportedFfmpegVideoFormats;
    private ?string $firstInputVideoExt = null;
    private SymfonyStyle $io;
    private readonly string $fromRoot;

    //###> DEFAULT ###

    public function __construct(
        private readonly SluggerInterface    $slugger,
        private readonly Filesystem          $filesystem,
        private readonly TranslatorInterface $t,
        private readonly RegexService        $regexService,
        string                               $supportedFfmpegVideoFormats,
        string                               $supportedFfmpegAudioFormats,
        private readonly string              $ffmpegAbsPath,
        private readonly string              $ffmpegAlgorithmForInputVideo,
        private readonly string              $ffmpegAlgorithmForInputAudio,
        private readonly string              $ffmpegAlgorithmForOutputVideo,
        private string                       $endmarkOutputVideoFilename,
        private readonly bool                $showFilmHelpInfo,
        //
        ?string                              $name = null,
    )
    {
        parent::__construct(name: $name);

        $this->fromRoot = Path::normalize(\getcwd());

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
            . ')';
        $this->supportedFfmpegAudioFormats = ''
            . '[.](?i)(?:'
            . $normalizeFormats($supportedFfmpegAudioFormats)
            . ')';
        $this->arrayOfSupportedFfmpegVideoFormats = \array_values(\array_unique(\array_map(
            static fn($v): string => \mb_strtolower((string)$v),
            \array_filter(
                \explode(
                    '|',
                    $normalizeFormats($supportedFfmpegVideoFormats),
                ),
                static fn($v): bool => !empty($v),
            ),
        )));
        $this->arrayOfSupportedFfmpegAudioFormats = \array_values(\array_unique(\array_map(
            static fn($v): string => \mb_strtolower((string)$v),
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

    protected function execute(
        InputInterface  $input,
        OutputInterface $output,
    ): int
    {
        $this->io = new SymfonyStyle($input, $output);

        // todo: lock

        if ($this->showFilmHelpInfo) {
            $this->dumpHelpInfo($output);
        }

        $this->fillInCommandParts();

        $this->dumpCommandParts($output);

        if (false === $this->io->confirm('Ok?')) {
            $this->io->warning($this->t->trans('EXIT'));
            return Command::SUCCESS;
        }

        if (false === $this->ffmpegExec($output)) {
            return Command::FAILURE;
        }

        $this->io->success('FINISH');

        return Command::SUCCESS;
    }

    //###> HELPER ###

    private function assignNonExistentToDirname(): void
    {
        /* more safe
        $newDirname = (string) $this->slugger->slug(
            (string) $this->gsServiceCarbonFactory->make(new \DateTime)
        );
        */
        $newDirname = \str_replace(':', '_', (string)Carbon::now('UTC'));

        while (\is_dir($newDirname)) {
            $newDirname = (string)$this->slugger->slug(Uuid::v1());
        }
        $this->toDirname = $newDirname;
    }

    private function dumpHelpInfo(
        OutputInterface $output,
    ): void
    {
        $this->io->text([
            $this->t->trans('СПРАВКА:'),
            '',
            $this->t->trans('Открывай консоль в месте расположения видео.'),
        ]);

        $this->io->warning([
            $this->t->trans('Для того чтобы к видео был найден нужный аудио файл:'),
            $this->t->trans('1) Аудио файл должен быть назван в точности как видео файл (расширение не учитывается)'),
            $this->t->trans('2) Аудио файл должен находится во вложенности не более 1 папки относительно видео'),
        ]);

        $this->io->text([
            $this->t->trans('Для объединённых видео файлов создаётся новая, гарантированно уникальная папка.'),
        ]);

        $this->io->info([
            $this->t->trans('Программа объёдиняет видео с аудио в новый видео файл'),
            $this->t->trans('Исходные видео и аудио остаются прежними как есть (не изменяются)'),
        ]);
    }

    private function ffmpegExec(
        OutputInterface $output,
    ): bool
    {
        if (empty($this->commandParts) || $this->toDirname === null) {
            $this->io->error($this->t->trans('ERROR'));
            return false;
        }

        $this->filesystem->mkdir(StringService::getPath($this->fromRoot, $this->toDirname));

        $resultsFilenames = [];
        \array_walk($this->commandParts, function (&$commandPart) use (&$resultsFilenames, &$output) {
            [
                'inputVideoFilename' => $inputVideoFilename,
                'inputAudioFilename' => $inputAudioFilename,
                'outputVideoFilename' => $outputVideoFilename,
            ] = $commandPart;

            // ffmpeg algorithm
            $ffmpegAbsPath = Path::normalize($this->ffmpegAbsPath);
            if (!\is_file($ffmpegAbsPath)) {
                $ffmpegAbsPath = (new ExecutableFinder())->find('ffmpeg', 'ffmpeg');
            }

            if (null === $ffmpegAbsPath || !\is_file($ffmpegAbsPath)) {
                $this->io->error([
                    $this->t->trans('Исполняемый ffmpeg файл не найден'),
                    $this->t->trans('В файле ".env.local" этого прокета укажи правильный путь переменной "FFMPEG_ABSOLUTE_PATH" к файлу ffmpeg'),
                ]);
                return false;
            }

            $command = '"' . Path::normalize($ffmpegAbsPath) . '"'
                . u($this->ffmpegAlgorithmForInputVideo)->ensureEnd(' ')->ensureStart(' ')
                . '"' . $inputVideoFilename . '"'
                . u($this->ffmpegAlgorithmForInputAudio)->ensureEnd(' ')->ensureStart(' ')
                . '"' . $inputAudioFilename . '"'
                . u($this->ffmpegAlgorithmForOutputVideo)->ensureEnd(' ')->ensureStart(' ')
                . '"' . $outputVideoFilename . '"';

            $process = Process::fromShellCommandline($command);
            try {
                $process->mustRun(function ($type, $buffer) use ($output) {
                    $cursor = new Cursor($output);
                    $cursor->moveUp();
                    $cursor->clearLine();
                    $output->writeln($buffer);
                });
            } catch (\Exception $exception) {
                $this->io->error($this->t->trans('error.os.ffmpeg', ['%ffmpeg%' => $ffmpegAbsPath]));
                throw $exception;
            }

            // dump
            $outputVideoFilename = $this->makePathRelative($outputVideoFilename);
            $this->io->warning([
                $outputVideoFilename . u('(' . $this->t->trans('ready') . ')')->ensureStart(' '),
            ]);
            $this->io->newLine(2);

            $resultsFilenames [] = $outputVideoFilename;
        });

        if (\count($resultsFilenames) > 1) {
            $this->io->info([
                $this->t->trans('ИТОГ:'),
                ...$resultsFilenames,
            ]);
        }

        return true;
    }

    private function fillInCommandParts(): void
    {
        $this->assignNonExistentToDirname();

        //###> HUMAN SORT
        $humanSort = static fn($l, $r): bool => (
            ((int)\preg_replace('~[^0-9]+~', '', $l)) > ((int)\preg_replace('~[^0-9]+~', '', $r))
        );

        //###> EXT SORT
        $arrayOfSupportedFfmpegVideoFormats = $this->arrayOfSupportedFfmpegVideoFormats;
        $extSortPrivate = $this->extSort(...);
        $extSort = static fn(
            $l,
            $r,
        ): bool => $extSortPrivate($l, $r, $arrayOfSupportedFfmpegVideoFormats);

        $finderInputVideoFilenames = (new Finder())
            ->in($this->fromRoot)
            ->sort($humanSort)->sort($extSort)
            ->files()
            ->ignoreUnreadableDirs()
            ->depth(0)
            ->name($this->arrayOfSupportedFfmpegVideoFormatsRegex);

        $arrayInputVideos = \iterator_to_array($finderInputVideoFilenames, false);
        if (isset($arrayInputVideos[0])) {
            $firstInputVideoExt = $arrayInputVideos[0]->getExtension();
            if ($this->firstInputVideoExt === null) {
                $this->firstInputVideoExt = $firstInputVideoExt;
            }
        }

        foreach ($finderInputVideoFilenames as $finderInputVideoFilename) {
            ++$this->allVideosCount;

            $inputAudioFilename = $this->getInputAudioFilename($finderInputVideoFilename);
            if ($inputAudioFilename === null) {
                continue;
            }

            $inputVideoFilename = $finderInputVideoFilename->getFilename();

            $outputVideoFilename = ''

                . $finderInputVideoFilename->getFilenameWithoutExtension()
                . $this->endmarkOutputVideoFilename
                // OR INSTEAD, JUST ENSURE
                //. (string) u($finderInputVideoFilename->getFilenameWithoutExtension())->ensureEnd($this->endmarkOutputVideoFilename)

                . '.'
                . $finderInputVideoFilename->getExtension();

            $this->commandParts[] = [
                'inputVideoFilename' => StringService::getPath(
                    $this->fromRoot,
                    $inputVideoFilename,
                ),
                'inputAudioFilename' => StringService::getPath(
                    $this->fromRoot,
                    $inputAudioFilename,
                ),
                'outputVideoFilename' => StringService::getPath(
                    $this->fromRoot,
                    $this->toDirname,
                    $outputVideoFilename,
                ),
            ];
        }
    }

    private function dumpCommandParts(
        OutputInterface $output,
    ): void
    {
        if (empty($this->commandParts)) {
            $this->io->success(
                $this->t->trans('Нечего соединять')
            );
            exit();
        }

        $infos = [
            $this->t->trans('Видео'),
            $this->t->trans('Аудио'),
            $this->t->trans('Результат'),
        ];

        foreach (
            $this->commandParts as ['inputVideoFilename' => $inputVideoFilename,
            'inputAudioFilename' => $inputAudioFilename,
            'outputVideoFilename' => $outputVideoFilename,]) {
            $inputVideoFilename = $this->makePathRelative($inputVideoFilename);
            $inputAudioFilename = $this->makePathRelative($inputAudioFilename);
            $outputVideoFilename = $this->makePathRelative($outputVideoFilename);

            $inputVideoFormat = '<bg=yellow;fg=black%s>';
            $inputAudioFormat = '<bg=white;fg=black%s>';
            $outputVideoFormat = '<bg=green;fg=black%s>';

            $this->io->createTable()
                ->setVertical()
                ->setStyle((new TableStyle())
                    ->setHorizontalBorderChars(' ')
                    ->setVerticalBorderChars('')
                    ->setDefaultCrossingChar('')
                )
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
                ->render();
        }

        $sumUpStrings = [
            $this->t->trans('Всего видео'),
            $this->t->trans('Видео с переводами'),
        ];

        //###>
        $allFoundVideos = $this->allVideosCount;

        $videosWithAudio = \count($this->commandParts);
        $videoWithAudioFormat = '<bg=black;fg=white%s>';
        $videoWithAudioDopFormat = '';

        if ($allFoundVideos != $videosWithAudio) {
            $videoWithAudioDopFormat = ',bold';
        }

        $this->io->createTable()
            ->setVertical()
            ->setHeaders([
                $sumUpStrings[0],
                \sprintf($videoWithAudioFormat, ';options=underscore' . $videoWithAudioDopFormat) . $sumUpStrings[1] . '</>',
            ])
            ->setRows([
                [
                    $allFoundVideos,
                    \sprintf($videoWithAudioFormat, ';options=underscore' . $videoWithAudioDopFormat) . $videosWithAudio . '</>',
                ],
            ])
            ->render();
    }

    private function getInputAudioFilename(
        SplFileInfo $finderInputVideoFilename,
    ): ?string
    {
        $inputAudioFilename = null;
        $inputVideoFilenameWithoutExtension = $finderInputVideoFilename->getFilenameWithoutExtension();
        $inputVideoFilenameWithExtension = $finderInputVideoFilename->getRelativePathname();

        //###> SORT
        $shorterFirst = static fn($l, $r): bool => (
            \mb_strlen($l->getRelativePathname()) > \mb_strlen($r->getRelativePathname())
        );
        $arrayOfSupportedFfmpegAudioFormats = $this->arrayOfSupportedFfmpegAudioFormats;
        $extSortPrivate = $this->extSort(...);
        $extSort = static fn(
            $l,
            $r,
        ): bool => $extSortPrivate($l, $r, $arrayOfSupportedFfmpegAudioFormats);

        $finderInputAudioFilenames = (new Finder())
            ->in($this->fromRoot)
            ->files()
            ->sort($extSort)->sort($shorterFirst)
            ->ignoreUnreadableDirs()
            ->depth(self::INPUT_AUDIO_FIND_DEPTH)
            ->name(
                $regex = '~^'
                    . $this->regexService->getEscapedStrings($inputVideoFilenameWithoutExtension)
                    . $this->supportedFfmpegAudioFormats
                    . '$~'
            );

        if ($this->firstInputVideoExt !== null) {
            $audioNotRelNameRegex = '~^'
                . $this->regexService->getEscapedStrings(
                    $finderInputVideoFilename->getRelativePathname(),
                )
                . '$~u';
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

    private function makePathRelative(string $needyPath): string
    {
        return \rtrim($this->filesystem->makePathRelative($needyPath, $this->fromRoot), '/');
    }

    private function extSort(
        SplFileInfo $l,
        SplFileInfo $r,
        array       $supported,
    ): bool
    {
        $supported = \array_flip($supported);

        $Lkey = \mb_strtolower($l->getExtension());
        $Rkey = \mb_strtolower($r->getExtension());

        if (
            false
            || !isset($supportedVideoFormatsFlipped[$Lkey])
            || !isset($supportedVideoFormatsFlipped[$Rkey])
        ) {
            return false;
        }

        return $supported[$Lkey] > $supported[$Rkey];
    }
}
