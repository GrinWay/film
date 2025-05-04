What's this program about?
---

`film join` is based on the ffmpeg program, and uses to join video and audio in one video file.

Undoubtedly, you can translate the app into russian language or change the ffmpeg's algorithm (see the [Advanced](#advanced) section).

It's highly useful for anime-watchers.
As a rule of thumb, translations are never joined with animes FULL HD or higher.
Download and join video with chosen audio translations.

<p align="center">
  <img alt="anime gif" src="https://github.com/GrinWay/film/blob/main/docs/media/gif/docs.gif" />
</p>

Installation
---

1. [Install all of it](#install-all-of-it)
1. [Install the program](#install-the-program)
1. [Operating System Environment Variables](#operating-system-environment-variables)
1. [FFMPEG installation](#ffmpeg-installation)
1. [Final step](#final-step)
1. [Advanced](#advanced)

### Install all of it

- [php](https://www.php.net/downloads.php)
- [composer](https://getcomposer.org/download/)
- [ffmpeg](https://www.ffmpeg.org/)
- - Described later
- [git_bash](https://git-scm.com/downloads)
- - You can avoid installing git_bash terminal but if it is you'll have to execute all commands from the `init.sh` file manually

### Install the program

Choose the directory where you want to situate this program.<br/>
Open exactly the git_bash console in the chosen directory and execute
```console
git clone https://github.com/GrinWay/film && cd ./film && ./init.sh
```

### Operating System Environment Variables

Add the absolute path to "ROOT_DIRECTORY_OF_THIS_PROJECT/bin" directory into "Operating System Environment Variables"

Google it if you don't know what it's about.

### FFMPEG installation

You have exactly 2 ways of doint it:

#### 1st way (easiest):

1. Download the [ffmpeg](https://ffmpeg.org/download.html) execution file exactly for your OS System.
1. Add the absolute path of the "ffmpeg" directory into "Operating System Environment Variables" to make it work via terminal like: `ffmpeg -v`

#### 2nd way (without operating system environment variables):

1. Download the [ffmpeg](https://ffmpeg.org/download.html) execution file exactly for your OS System.
1. Place it in the project or somewhere else, for example in the `ROOT_DIRECTORY_OF_THIS_PROJECT/bin/exe/ffmpeg/EXECUTION_FILE`
1. According to the chosen path write it down in your `ROOT_DIRECTORY_OF_THIS_PROJECT/.env.local`

```.env
FFMPEG_ABSOLUTE_PATH="%kernel.project_dir%/bin/exe/ffmpeg/EXECUTION_FILE"
```

## Final step

Restart the git_bash terminal.
You can already use the command 
```console
film join
```
in the directory where videos place and join video with audio on the particular depth.

Or, if you haven't set up git_bash console, you can use the usual windows console (cmd)
and execute the following command `php "ROOT_DIRECTORY_OF_THIS_PROJECT/bin/film" join`

But you have to admit that's not convenient.

Advanced
---

You can change the defined behaviour.
1. Create in root directory of this project a new file `touch ./.env.local`
1. Copy from `.env` file section `###> APP (CHANGE ME) ###`
1. Change `###> APP (CHANGE ME) ###` section, for instance, we can write down the following:
```.env
# That's the ".env.local" file

###> APP (CHANGE ME) ###
LOCALE='ru' # CHOSE THE "ru" LANGUAGE

# ffmpeg video and audio formats for searching
SUPPORTED_FFMPEG_VIDEO_FORMATS="MP4|AVI|MOV|FLV|WMV"
SUPPORTED_FFMPEG_AUDIO_FORMATS="mp3|flac|aac|wav|mka|ogg"
###< APP (CHANGE ME) ###
```
