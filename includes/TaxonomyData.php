<?php
/**
 * TaxonomyData.php
 *
 * Contiene los datos de referencia de las taxonomías existentes en WordPress (Gluglux).
 * Estos datos se usan para normalizar, cruzar y evitar duplicados al procesar
 * contenido extraído desde la fuente de scraping.
 *
 * Arquitectura WordPress:
 *   - Etiquetas  → Taxonomía por defecto de WordPress
 *   - Universos  → Taxonomía personalizada (CPT UI)
 *   - Tipos      → Taxonomía personalizada (CPT UI)
 *   - Autores    → Taxonomía personalizada (CPT UI)
 *   - Idiomas    → Taxonomía personalizada (CPT UI)
 *
 * @package ScrapApp
 * @subpackage Taxonomy
 */

class TaxonomyData
{
    /**
     * Lista de etiquetas existentes en WordPress.
     * Formato: Array de strings con los nombres normalizados.
     * TODAS en minúsculas y sin acentos para compatibilidad con WordPress.
     *
     * @var array<string>
     */
    private static array $tags = [
        '3d',
        'a color',
        'albina',
        'alien',
        'anal',
        'asfixia',
        'axila',
        'bbw',
        'beso negro',
        'bikini',
        'bondage',
        'borracha',
        'bukkake',
        'cambio de cuerpo',
        'chantaje',
        'chica demonio',
        'condon',
        'corrupcion',
        'cosplay',
        'culona',
        'cum',
        'cumflacion',
        'delantal',
        'doble anal',
        'doble penetracion',
        'dominacion femenina',
        'drogas',
        'duende',
        'elfa',
        'embarazo',
        'entrenador',
        'escuela',
        'exhibicionismo',
        'filmar',
        'footjob',
        'forzado',
        'furro',
        'futanari',
        'gimnasio',
        'hermana',
        'historia',
        'hombre viejo',
        'impregnacion',
        'incesto',
        'infiel',
        'juguetes',
        'lactancia materna',
        'latex',
        'lenceria',
        'lentes',
        'lesbiana',
        'loli',
        'madrastra',
        'madre',
        'mamada',
        'mamada rusa',
        'masturbacion',
        'milf',
        'monja',
        'monstruo',
        'morena',
        'musculosa',
        'navidad',
        'ninja',
        'ntr',
        'oficina',
        'oral (femenino)',
        'orco',
        'orgasmo (femenino)',
        'orgia',
        'parche en el ojo',
        'pelirroja',
        'pelo corto',
        'pelo largo',
        'pelo liso',
        'pelo negro',
        'pelo rizado',
        'pene grande',
        'pezones',
        'pezones invertidos',
        'piercing',
        'pies',
        'policia (mujer)',
        'pov',
        'primos',
        'profesora',
        'prostitucion',
        'rayos x',
        'rubia',
        'shorts',
        'shota',
        'sirvienta',
        'solo hombres',
        'solo mujeres',
        'tatuaje',
        'tentaculos',
        'tetas pequenas',
        'tetona',
        'time stop',
        'tomboy',
        'trio',
        'tsundere',
        'virgen',
        'yandere',
        'yukata',
    ];

    /**
     * MAPA DE EQUIVALENCIAS: tags del sitio origen (scraping) → tags del sitio destino (WordPress).
     *
     * Este diccionario resuelve el problema de taxonomías heterogéneas:
     * el sitio de scraping usa etiquetas en inglés (ej. "big breasts (female)")
     * mientras que el sitio destino (Gluglux) usa etiquetas en español (ej. "tetona").
     *
     * Formato: 'tag_origen' => 'tag_destino'
     * - La clave (tag_origen) es el valor exacto que se recibe del scraper, en minúsculas
     * - El valor (tag_destino) es el nombre CANÓNICO en WordPress (como está en $tags)
     *
     * TODAS las etiquetas destino están en minúsculas y sin acentos.
     *
     * @var array<string, string>
     */
    private static array $tagMappings = [
        // ── Fisico / Anatomia ──
        'big breasts (female)'  => 'tetona',
        'big breasts'           => 'tetona',
        'large breasts'         => 'tetona',
        'huge breasts'          => 'tetona',
        'big areolae'           => 'tetona',
        'small breasts'         => 'tetas pequenas',
        'flat chest'            => 'tetas pequenas',
        'oppai loli'            => 'tetas pequenas',
        'big penis'             => 'pene grande',
        'large penis'           => 'pene grande',
        'huge penis'            => 'pene grande',
        'big dick'              => 'pene grande',
        'horse cock'            => 'pene grande',
        'monster penis'         => 'pene grande',
        'nipples'               => 'pezones',
        'inverted nipples'      => 'pezones invertidos',
        'puffy nipples'         => 'pezones',
        'nipple fuck'           => 'pezones',
        'feet'                  => 'pies',
        'foot fetish'           => 'pies',
        'sole'                  => 'pies',
        'footjob'               => 'footjob',
        'armpit'                => 'axila',
        'armpits'               => 'axila',
        'armpit fetish'         => 'axila',
        'sweating'              => 'axila',
        'sweat'                 => 'axila',
        'muscle'                => 'musculosa',
        'muscular'              => 'musculosa',
        'muscle woman'          => 'musculosa',
        'muscles'               => 'musculosa',
        'thick thighs'          => 'culona',
        'thick'                 => 'culona',
        'big ass'               => 'culona',
        'large butt'            => 'culona',
        'ass'                   => 'culona',
        'bbw'                   => 'bbw',
        'fat'                   => 'bbw',
        'chubby'                => 'bbw',
        'plus size'             => 'bbw',
        'wide hips'             => 'culona',

        // ── Actos Sexuales ──
        'anal'                  => 'anal',
        'anal sex'              => 'anal',
        'double anal'           => 'doble anal',
        'double penetration'    => 'doble penetracion',
        'dp'                    => 'doble penetracion',
        'vaginal'               => 'anal',      // generico
        'sex'                   => 'anal',
        'oral'                  => 'mamada',
        'fellatio'              => 'mamada',
        'blowjob'               => 'mamada',
        'blowjob (female)'      => 'mamada',
        'blowjob (male)'        => 'mamada',
        'deep throat'           => 'mamada',
        'irrumatio'             => 'mamada',
        'cock sucking'          => 'mamada',
        'cunnilingus'           => 'oral (femenino)',
        'oral (female)'         => 'oral (femenino)',
        'oral (male)'           => 'mamada',
        'cunnilingus (female)'  => 'oral (femenino)',
        'paizuri'               => 'mamada rusa',
        'titfuck'               => 'mamada rusa',
        'titjob'                => 'mamada rusa',
        'paizuri (female)'      => 'mamada rusa',
        'breast job'            => 'mamada rusa',
        'nakadashi'             => 'cum',
        'creampie'              => 'cum',
        'cum inside'            => 'cum',
        'cum in mouth'          => 'cum',
        'facial'                => 'cum',
        'cum on face'           => 'cum',
        'cum on body'           => 'cum',
        'cumflation'            => 'cumflacion',
        'cumflation (female)'   => 'cumflacion',
        'inflation'             => 'cumflacion',
        'stomach deformation'   => 'cumflacion',
        'masturbation'          => 'masturbacion',
        'masturbating'          => 'masturbacion',
        'fingering'             => 'masturbacion',
        'vibrator'              => 'juguetes',
        'dildo'                 => 'juguetes',
        'sex toys'              => 'juguetes',
        'toys'                  => 'juguetes',
        'onahole'               => 'juguetes',
        'bondage'               => 'bondage',
        'bdsm'                  => 'bondage',
        'shibari'               => 'bondage',
        'kinbaku'               => 'bondage',
        'restraints'            => 'bondage',
        'tied up'               => 'bondage',
        'handcuffs'             => 'bondage',
        'choking'               => 'asfixia',
        'strangling'            => 'asfixia',
        'breath play'           => 'asfixia',
        'asphyxiation'          => 'asfixia',
        'foot worship'          => 'pies',
        'rimjob'                => 'anal',
        'ahegao'                => 'orgasmo (femenino)',
        'orgasm'                => 'orgasmo (femenino)',
        'orgasm (female)'       => 'orgasmo (femenino)',
        'multiple orgasms'      => 'orgasmo (femenino)',
        'skull fucking'         => 'mamada',
        'face fucking'          => 'mamada',
        'face sitting'          => 'oral (femenino)',
        'tribadism'             => 'lesbiana',

        // ── Tipos de Contenido / Categorias ──
        'monster'               => 'monstruo',
        'monster girl'          => 'chica demonio',
        'monster boy'           => 'monstruo',
        'demon'                 => 'chica demonio',
        'demon girl'            => 'chica demonio',
        'demon boy'             => 'chica demonio',
        'succubus'              => 'chica demonio',
        'incubus'               => 'chica demonio',
        'elf'                   => 'elfa',
        'dark elf'              => 'elfa',
        'high elf'              => 'elfa',
        'fairy'                 => 'duende',
        'goblin'                => 'orco',
        'orc'                   => 'orco',
        'tentacles'             => 'tentaculos',
        'tentacle'              => 'tentaculos',
        'tentacle rape'         => 'tentaculos',
        'alien'                 => 'alien',
        'robot'                 => 'monstruo',
        'android'               => 'monstruo',
        'cyborg'                => 'monstruo',
        'ghost'                 => 'chica demonio',
        'spirit'                => 'chica demonio',
        'zombie'                => 'monstruo',
        'vampire'               => 'chica demonio',
        'werewolf'              => 'monstruo',
        'cat girl'              => 'furro',
        'catgirl'               => 'furro',
        'cat ears'              => 'furro',
        'nekomimi'              => 'furro',
        'fox girl'              => 'furro',
        'kitsune'               => 'furro',
        'dog girl'              => 'furro',
        'kemonomimi'            => 'furro',
        'animal ears'           => 'furro',
        'furry'                 => 'furro',
        'furro'                 => 'furro',
        'scalie'                => 'furro',

        // ── Ropa / Vestimenta ──
        'glasses'               => 'lentes',
        'eyeglasses'            => 'lentes',
        'spectacles'            => 'lentes',
        'school uniform'        => 'escuela',
        'schoolgirl uniform'    => 'escuela',
        'seifuku'               => 'escuela',
        'uniform'               => 'escuela',
        'bikini'                => 'bikini',
        'swimsuit'              => 'bikini',
        'swimwear'              => 'bikini',
        'swimming suit'         => 'bikini',
        'one piece swimsuit'    => 'bikini',
        'maid'                  => 'sirvienta',
        'maid outfit'           => 'sirvienta',
        'maid uniform'          => 'sirvienta',
        'nun'                   => 'monja',
        'nun outfit'            => 'monja',
        'cosplay'               => 'cosplay',
        'cosplaying'            => 'cosplay',
        'costume'               => 'cosplay',
        'lingerie'              => 'lenceria',
        'panties'               => 'lenceria',
        'underwear'             => 'lenceria',
        'bra'                   => 'lenceria',
        'stockings'             => 'lenceria',
        'pantyhose'             => 'lenceria',
        'thighhighs'            => 'lenceria',
        'garter belt'           => 'lenceria',
        'corset'                => 'lenceria',
        'latex'                 => 'latex',
        'leather'               => 'latex',
        'rubber'                => 'latex',
        'vinyl'                 => 'latex',
        'pvc'                   => 'latex',
        'apron'                 => 'delantal',
        'waitress'              => 'sirvienta',
        'police'                => 'policia (mujer)',
        'policewoman'           => 'policia (mujer)',
        'female police'         => 'policia (mujer)',
        'cop'                   => 'policia (mujer)',
        'police uniform'        => 'policia (mujer)',
        'nurse'                 => 'sirvienta',
        'nurse outfit'          => 'sirvienta',
        'office lady'           => 'oficina',
        'ol'                    => 'oficina',
        'office'                => 'oficina',
        'business suit'         => 'oficina',
        'suit'                  => 'oficina',
        'salarywoman'           => 'oficina',
        'yukata'                => 'yukata',
        'kimono'                => 'yukata',
        'shorts'                => 'shorts',
        'short shorts'          => 'shorts',
        'miniskirt'             => 'shorts',
        'skirt'                 => 'shorts',
        'tattoo'                => 'tatuaje',
        'tattoos'               => 'tatuaje',
        'body painting'         => 'tatuaje',
        'piercing'              => 'piercing',
        'piercings'             => 'piercing',
        'naval piercing'        => 'piercing',
        'collared'              => 'bondage',

        // ── Relaciones / Roles ──
        'incest'                => 'incesto',
        'mother'                => 'madre',
        'mom'                   => 'madre',
        'mother and son'        => 'madre',
        'mother and daughter'   => 'madre',
        'sister'                => 'hermana',
        'little sister'         => 'hermana',
        'younger sister'        => 'hermana',
        'older sister'          => 'hermana',
        'onee-san'              => 'hermana',
        'brother and sister'    => 'incesto',
        'father and daughter'   => 'incesto',
        'father'                => 'hombre viejo',
        'dad'                   => 'hombre viejo',
        'aunt'                  => 'madrastra',
        'stepmother'            => 'madrastra',
        'step mom'              => 'madrastra',
        'stepsister'            => 'hermana',
        'step sister'           => 'hermana',
        'cousin'                => 'primos',
        'twins'                 => 'primos',
        'twin sisters'          => 'hermana',
        'milf'                  => 'milf',
        'milf (female)'         => 'milf',
        'teacher'               => 'profesora',
        'sensei'                => 'profesora',
        'female teacher'        => 'profesora',
        'trainer'               => 'entrenador',
        'coach'                 => 'entrenador',
        'gym'                   => 'gimnasio',
        'student'               => 'escuela',
        'schoolgirl'            => 'escuela',
        'delinquent'            => 'escuela',
        'yandere'               => 'yandere',
        'tsundere'              => 'tsundere',
        'old man'               => 'hombre viejo',
        'old man (male)'        => 'hombre viejo',
        'aged care'             => 'hombre viejo',
        'grandpa'               => 'hombre viejo',
        'elderly man'           => 'hombre viejo',
        'virgin'                => 'virgen',
        'virginity'             => 'virgen',
        'first time'            => 'virgen',
        'defloration'           => 'virgen',
        'prostitution'          => 'prostitucion',
        'prostitute'            => 'prostitucion',
        'hooker'                => 'prostitucion',
        'escort'                => 'prostitucion',
        'paid sex'              => 'prostitucion',
        'whore'                 => 'prostitucion',
        'slut'                  => 'prostitucion',

        // ── Situaciones / Tramas ──
        'netorare'              => 'ntr',
        'ntr'                   => 'ntr',
        'netorare (female)'     => 'ntr',
        'netorare (male)'       => 'ntr',
        'netorase'              => 'ntr',
        'netori'                => 'ntr',
        'cuckold'               => 'ntr',
        'cuckolding'            => 'ntr',
        'cheating'              => 'infiel',
        'cheating wife'         => 'infiel',
        'adultery'              => 'infiel',
        'infidelity'            => 'infiel',
        'affair'                => 'infiel',
        'love triangle'         => 'trio',
        'mind break'            => 'corrupcion',
        'corruption'            => 'corrupcion',
        'mind control'          => 'corrupcion',
        'brainwashing'          => 'corrupcion',
        'hypnosis'              => 'corrupcion',
        'hypnotized'            => 'corrupcion',
        'drugs'                 => 'drogas',
        'drug'                  => 'drogas',
        'aphrodisiac'           => 'drogas',
        'date rape drug'        => 'drogas',
        'drunk'                 => 'borracha',
        'alcohol'               => 'borracha',
        'drinking'              => 'borracha',
        'drunken'               => 'borracha',
        'blackmail'             => 'chantaje',
        'extortion'             => 'chantaje',
        'body swap'             => 'cambio de cuerpo',
        'gender bender'         => 'cambio de cuerpo',
        'gender swap'           => 'cambio de cuerpo',
        'sex change'            => 'cambio de cuerpo',
        'transformation'        => 'cambio de cuerpo',
        'exhibitionism'         => 'exhibicionismo',
        'exhibitionist'         => 'exhibicionismo',
        'public sex'            => 'exhibicionismo',
        'public nudity'         => 'exhibicionismo',
        'flashing'              => 'exhibicionismo',
        'voyeur'                => 'filmar',
        'voyeurism'             => 'filmar',
        'filming'               => 'filmar',
        'recorded'              => 'filmar',
        'camera'                => 'filmar',
        'hidden camera'         => 'filmar',
        'peeping'               => 'filmar',
        'pregnancy'             => 'embarazo',
        'pregnant'              => 'embarazo',
        'impregnation'          => 'impregnacion',
        'forced pregnancy'      => 'impregnacion',
        'breeding'              => 'impregnacion',
        'lactation'             => 'lactancia materna',
        'breastfeeding'         => 'lactancia materna',
        'milking'               => 'lactancia materna',
        'breast milk'           => 'lactancia materna',
        'x-ray'                 => 'rayos x',
        'xray'                  => 'rayos x',
        'see through'           => 'rayos x',
        'visible organs'        => 'rayos x',
        'transparent'           => 'rayos x',
        'time stop'             => 'time stop',
        'time stop (female)'    => 'time stop',
        'frozen'                => 'time stop',
        'freeze'                => 'time stop',
        'halloween'             => 'cosplay',
        'christmas'             => 'navidad',
        'xmas'                  => 'navidad',
        'winter'                => 'navidad',
        'snow'                  => 'navidad',

        // ── Violencia / Forzado ──
        'chikan'                => 'forzado',
        'groping'               => 'forzado',
        'forced'                => 'forzado',
        'rape'                  => 'forzado',
        'gang rape'             => 'forzado',
        'non consensual'        => 'forzado',
        'dubious consent'       => 'forzado',
        'kidnapping'            => 'forzado',
        'kidnapped'             => 'forzado',

        // ── Orientacion / Genero ──
        'yuri'                  => 'lesbiana',
        'lesbian'               => 'lesbiana',
        'girl on girl'          => 'lesbiana',
        'gl'                    => 'lesbiana',
        'futanari'              => 'futanari',
        'dickgirl'              => 'futanari',
        'dickgirl on male'      => 'futanari',
        'dickgirl on girl'      => 'futanari',
        'dickgirl on dickgirl'  => 'futanari',
        'dildo girl'            => 'futanari',
        'femdom'                => 'dominacion femenina',
        'female dominant'       => 'dominacion femenina',
        'female domination'     => 'dominacion femenina',
        'male domination'       => 'forzado',
        'shota'                 => 'shota',
        'shotacon'              => 'shota',
        'loli'                  => 'loli',
        'lolicon'               => 'loli',
        'lolicon (female)'      => 'loli',
        'tomgirl'               => 'tomboy',
        'tomboy'                => 'tomboy',
        'crossdressing'         => 'tomboy',
        'crossdresser'          => 'tomboy',
        'trap'                  => 'tomboy',
        'gender bender male'    => 'cambio de cuerpo',
        'solo female'           => 'solo mujeres',
        'sole female'           => 'solo mujeres',
        'females only'          => 'solo mujeres',
        'female only'           => 'solo mujeres',
        'solo male'             => 'solo hombres',
        'male only'             => 'solo hombres',
        'group'                 => 'orgia',
        'orgy'                  => 'orgia',
        'threesome'             => 'trio',
        'foursome'              => 'orgia',
        'gangbang'              => 'orgia',
        'bukkake'               => 'bukkake',
        'pov'                   => 'pov',
        'point of view'         => 'pov',
        'first person'          => 'pov',
        'condom'                => 'condon',
        'condomless'            => 'cum',
        'wrapped'               => 'condon',
        'wearing condom'        => 'condon',
        'no condom'             => 'cum',

        // ── Estilos / Misc ──
        'story'                 => 'historia',
        'story arc'             => 'historia',
        'plot'                  => 'historia',
        'storyline'             => 'historia',
        'full story'            => 'historia',
        'full color'            => 'a color',
        'color'                 => 'a color',
        'colored'               => 'a color',
        'ninja'                 => 'ninja',
        'samurai'               => 'ninja',
        'dark skin'             => 'morena',
        'tanned'                => 'morena',
        'tan'                   => 'morena',
        'tan lines'             => 'morena',
        'gym tan'               => 'morena',
        'blonde'                => 'rubia',
        'blond'                 => 'rubia',
        'blonde hair'           => 'rubia',
        'red hair'              => 'pelirroja',
        'ginger'                => 'pelirroja',
        'orange hair'           => 'pelirroja',
        'black hair'            => 'pelo negro',
        'dark hair'             => 'pelo negro',
        'short hair'            => 'pelo corto',
        'long hair'             => 'pelo largo',
        'ponytail'              => 'pelo corto',
        'twin tails'            => 'pelo corto',
        'twinbraids'            => 'pelo corto',
        'braids'                => 'pelo corto',
        'bunny girl'            => 'cosplay',
        'bunny outfit'          => 'cosplay',
        'playboy bunny'         => 'cosplay',
        'straight hair'         => 'pelo liso',
        'curly hair'            => 'pelo rizado',
        'hair decoration'       => 'pelo corto',
        'eyepatch'              => 'parche en el ojo',
        'mask'                  => 'ninja',
        'veil'                  => 'monja',
        'chinese dress'         => 'cosplay',
        'cheongsam'             => 'cosplay',
        'qipao'                 => 'cosplay',
        'horse ears'            => 'furro',
        'horn'                  => 'chica demonio',
        'horns'                 => 'chica demonio',
        'wings'                 => 'chica demonio',
        'tail'                  => 'furro',
        'pointy ears'           => 'elfa',
        'monster ears'          => 'furro',
        'dark past'             => 'corrupcion',
        'tragic past'           => 'corrupcion',
        'ugly bastard'          => 'hombre viejo',
        'ugly'                  => 'hombre viejo',
        'ugly man'              => 'hombre viejo',
        'beast'                 => 'monstruo',
        'beastman'              => 'furro',
        'beast girl'            => 'furro',
        'centaur'               => 'monstruo',
        'minotaur'              => 'monstruo',
        'slime'                 => 'monstruo',
        'ghost girl'            => 'chica demonio',
        'dragon'                => 'monstruo',
        'dragon girl'           => 'chica demonio',
        'angel'                 => 'chica demonio',
        'fallen angel'          => 'chica demonio',
        'devil'                 => 'chica demonio',
        'goddess'               => 'chica demonio',
        'miko'                  => 'monja',
        'shrine maiden'         => 'monja',
        'priestess'             => 'monja',
        'witch'                 => 'chica demonio',
        'magical girl'          => 'chica demonio',
        'mahou shoujo'          => 'chica demonio',
        'idol'                  => 'cosplay',
        'pop idol'              => 'cosplay',
        'singer'                => 'cosplay',
        'actress'               => 'cosplay',
        'model'                 => 'cosplay',
        'stewardess'            => 'sirvienta',
        'flight attendant'      => 'sirvienta',
        'waitress'              => 'sirvienta',
        'bartender'             => 'sirvienta',
        'cafe'                  => 'sirvienta',
        'hostess'               => 'sirvienta',
        'princess'              => 'cosplay',
        'queen'                 => 'chica demonio',
        'empress'               => 'chica demonio',
        'nobility'              => 'historia',
        'slave'                 => 'bondage',
        'slave girl'            => 'bondage',
        'petplay'               => 'furro',
        'pet girl'              => 'furro',
        'leash'                 => 'bondage',
        'collar'                => 'bondage',

        // ── Mapeos especificos para 3hentai.net ──
        // Tags con modificador de genero que no tienen equivalente directo
        // se resuelven mediante el step 3 de matchExisting() (sin modificador).
        'anal (male)'              => 'anal',
        'anal intercourse (male)'  => 'anal',
    ];

    /**
     * Lista de universos (series) existentes en WordPress.
     * Formato: Array de strings con los nombres originales.
     *
     * @var array<string>
     */
    private static array $universes = [
        'Attack On Titan',
        'Avatar',
        'Bayonetta',
        'Ben 10',
        'Black Clover',
        'Bleach',
        'Chainsaw Man',
        'Cyberpunk Edgerunners',
        'Dandadan',
        'Danny Phantom',
        'Demon Slayer',
        'Dexter',
        'Dragon Ball',
        'Drama Total',
        'Evangelion',
        'Futurama',
        'Genshin Impact',
        'Gravity Falls',
        'Helluva Boss',
        'Hora de Aventura',
        'Hotel Transylvania',
        'Johnny Bravo',
        'Jovenes Titanes',
        'Jujutsu Kaisen',
        'Kick Buttowski',
        'Kim Possible',
        'La Liga de la Justicia',
        'Liga de la justicia',
        'Los Padrinos Magicos',
        'Los Picapiedra',
        'Los Simpson',
        'Miraculous',
        'Monsters Inc',
        'My Hero Academy',
        'Naruto',
        'Nier Automata',
        'One Piece',
        'One Punch Man',
        'Padre Americano',
        'Padre de familia',
        'Phineas and Ferb',
        'Pokemon',
        'Pucca',
        'Rick and Morty',
        'Samurai Jack',
        'Scooby Doo',
        'Spiderman',
        'Star vs Las Fuerzas del Mal',
        'Star Wars',
        'Steven Universe',
        'Sym-Bionic Titan',
        'The Amazing World of Gumball',
        'The Legend of Zelda',
        'The Owl House',
        'Thundercats',
        'Transformers',
        'un show mas',
        'Wall‑E',
    ];

    /**
     * Retorna la lista completa de etiquetas existentes.
     *
     * @return array<string>
     */
    public static function getTags(): array
    {
        return self::$tags;
    }

    /**
     * Retorna el mapa de equivalencias: tag_origen → tag_destino.
     *
     * @return array<string, string>
     */
    public static function getTagMappings(): array
    {
        return self::$tagMappings;
    }

    /**
     * Retorna el mapa de equivalencias con claves normalizadas para busqueda rapida.
     *
     * @return array<string, string>  normalized_source_key → target_tag (minusculas)
     */
    public static function getTagMappingsNormalized(): array
    {
        $normalized = [];
        foreach (self::$tagMappings as $source => $target) {
            $key = self::normalizeForSearch($source);
            $normalized[$key] = mb_strtolower($target, 'UTF-8');
        }
        return $normalized;
    }

    /**
     * Retorna la lista completa de universos existentes.
     *
     * @return array<string>
     */
    public static function getUniverses(): array
    {
        return self::$universes;
    }

    /**
     * Retorna las etiquetas en un formato normalizado para busqueda.
     * Todas en minusculas, con espacios y sin caracteres extraños.
     *
     * @return array<string>  Claves normalizadas (lowercase) + valores originales
     */
    public static function getTagsNormalized(): array
    {
        $normalized = [];
        foreach (self::$tags as $tag) {
            $key = self::normalizeForSearch($tag);
            $normalized[$key] = $tag;
        }
        return $normalized;
    }

    /**
     * Retorna los universos en un formato normalizado para busqueda.
     * Todas en minusculas para matching fuzzy.
     *
     * @return array<string, string>  Clave normalizada → valor original
     */
    public static function getUniversesNormalized(): array
    {
        $normalized = [];
        foreach (self::$universes as $universe) {
            $key = self::normalizeForSearch($universe);
            $normalized[$key] = $universe;
        }
        return $normalized;
    }

    /**
     * Normaliza un string para busqueda: minusculas, sin puntuacion redundante,
     * espacios simples.
     *
     * @param string $text
     * @return string
     */
    public static function normalizeForSearch(string $text): string
    {
        $text = mb_strtolower(trim($text), 'UTF-8');
        // Reemplazar guiones y múltiples espacios por un solo espacio
        $text = preg_replace('/[–—_-]+/u', ' ', $text);
        $text = preg_replace('/\s+/u', ' ', $text);
        return trim($text);
    }
}
