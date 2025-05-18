<?php
namespace gazer22;

use Monolog\Logger;
use Monolog\Level;
use Monolog\Handler\StreamHandler;
use Monolog\Formatter\LineFormatter;


// phpcs:disable WordPress
// phpcs:disable Squiz.Commenting
// phpcs:disable Generic.Commenting.DocComment.ShortNotCapital

/**
 * phpFITFileAnalysis
 * =====================
 * A PHP class for Analysing FIT files created by Garmin GPS devices.
 * Adrian Gibbons, 2015
 * Adrian.GitHub@gmail.com
 *
 * G Frogley edits:
 * Added code to generate TRIMPexp and hrIF (Intensity Factor) value to measure session if Power is not present (June 2015).
 * Added code to generate Quadrant Analysis data (September 2015).
 *
 * Rafael Nájera edits:
 * Added code to support compressed timestamps (March 2017).
 *
 * J. Karpick edits:
 * Refactored so data are loaded to database in batches and cached when
 * requested.
 *
 * https://github.com/adriangibbons/phpFITFileAnalysis
 * http://www.thisisant.com/resources/fit
 */

if ( ! defined( 'DEFINITION_MESSAGE' ) ) {
	define( 'DEFINITION_MESSAGE', 1 );
}
if ( ! defined( 'DATA_MESSAGE' ) ) {
	define( 'DATA_MESSAGE', 0 );
}

/*
 * This is the number of seconds difference between FIT and Unix timestamps.
 * FIT timestamps are seconds since UTC 00:00:00 Dec 31 1989 (source FIT SDK)
 * Unix time is the number of seconds since UTC 00:00:00 Jan 01 1970
 */
if ( ! defined( 'FIT_UNIX_TS_DIFF' ) ) {
	define( 'FIT_UNIX_TS_DIFF', 631065600 );
}

class phpFITFileAnalysis {

	public $data_mesgs              = array();               // Used to store the data read from the file in associative arrays.
	private $dev_field_descriptions = array();
	private $options                = null;     // Options provided to __construct().
	private $file_contents          = '';       // FIT file is read-in to memory as a string, split into an array, and reversed. See __construct().
	private $file_pointer           = 0;        // Points to the location in the file that shall be read next.
	private $defn_mesgs             = array();  // Array of FIT 'Definition Messages', which describe the architecture, format, and fields of 'Data Messages'.
	private $defn_mesgs_all         = array();  // Keeps a record of all Definition Messages as index ($local_mesg_type) of $defn_mesgs may be reused in file.
	private $file_header            = array();  // Contains information about the FIT file such as the Protocol version, Profile version, and Data Size.
	private $php_trader_ext_loaded  = false;    // Is the PHP Trader extension loaded? Use $this->sma() algorithm if not available.
	private $types                  = null;     // Set by $endianness depending on architecture in Definition Message.
	private $garmin_timestamps      = false;    // By default the constant FIT_UNIX_TS_DIFF will be added to timestamps.
	private $file_buff              = false;    // Set to true to NOT pull entire file in to memory.  Read the file in pieces.
	private $data_table             = '';       // Base name for data tables in the database.
	private $tables_created         = array();  // Stores the name and columns of each table created.
	private $db;                                // PDO object for database connection.
	private $db_name;                           // Database name.
	private $db_user;                           // Database user.
	private $db_pass;                           // Database password.
	private $buffer_size = 1000;     // Number of messags to buffer and then load to DB in batch.
	public $logger;                             // Monolog logger object.

	// Enumerated data looked up by enumData().
	// Values from 'Profile.xls' contained within the FIT SDK.
	private $enum_data = array(
		'activity'            => array(
			0 => 'manual',
			1 => 'auto_multi_sport',
		),
		'ant_network'         => array(
			0 => 'public',
			1 => 'antplus',
			2 => 'antfs',
			3 => 'private',
		),
		'base_type'           => array(
			0 => 'enum',    // enum.
			1 => 'TINYINT',   // sint8.
			2 => 'TINYINT UNSIGNED',   // uint8.
			131 => 'SMALLINT', // sint16.
			132 => 'SMALLINT UNSIGNED', // uint16.
			133 => 'INT', // sint32.
			134 => 'INT UNSIGNED', // uint32.
			7 => 'VARCHAR', // string.
			136 => 'FLOAT', // float32.
			137 => 'DOUBLE', // float64.
			10 => 'uint8z', // uint8z.
			139 => 'TINYINT UNSIGNED', // uint16z.
			140 => 'INT UNSIGNED', // uint32z.
			13 => 'BINARY', // byte.
			142 => 'BIGINT', // sint64.
			143 => 'BIGINT UNSIGNED', // uint64.
			144 => 'BIGINT UNSIGNED', // uint64z.
		),
		'battery_status'      => array(
			1 => 'new',
			2 => 'good',
			3 => 'ok',
			4 => 'low',
			5 => 'critical',
			7 => 'unknown',
		),
		'body_location'       => array(
			0  => 'left_leg',
			1  => 'left_calf',
			2  => 'left_shin',
			3  => 'left_hamstring',
			4  => 'left_quad',
			5  => 'left_glute',
			6  => 'right_leg',
			7  => 'right_calf',
			8  => 'right_shin',
			9  => 'right_hamstring',
			10 => 'right_quad',
			11 => 'right_glute',
			12 => 'torso_back',
			13 => 'left_lower_back',
			14 => 'left_upper_back',
			15 => 'right_lower_back',
			16 => 'right_upper_back',
			17 => 'torso_front',
			18 => 'left_abdomen',
			19 => 'left_chest',
			20 => 'right_abdomen',
			21 => 'right_chest',
			22 => 'left_arm',
			23 => 'left_shoulder',
			24 => 'left_bicep',
			25 => 'left_tricep',
			26 => 'left_brachioradialis',
			27 => 'left_forearm_extensors',
			28 => 'right_arm',
			29 => 'right_shoulder',
			30 => 'right_bicep',
			31 => 'right_tricep',
			32 => 'right_brachioradialis',
			33 => 'right_forearm_extensors',
			34 => 'neck',
			35 => 'throat',
		),
		'display_heart'       => array(
			0 => 'bpm',
			1 => 'max',
			2 => 'reserve',
		),
		'display_measure'     => array(
			0 => 'metric',
			1 => 'statute',
		),
		'display_position'    => array(
			0  => 'degree',                // dd.dddddd
			1  => 'degree_minute',         // dddmm.mmm
			2  => 'degree_minute_second',  // dddmmss
			3  => 'austrian_grid',   // Austrian Grid (BMN)
			4  => 'british_grid',    // British National Grid
			5  => 'dutch_grid',      // Dutch grid system
			6  => 'hungarian_grid',  // Hungarian grid system
			7  => 'finnish_grid',    // Finnish grid system Zone3 KKJ27
			8  => 'german_grid',     // Gausss Krueger (German)
			9  => 'icelandic_grid',  // Icelandic Grid
			10 => 'indonesian_equatorial',  // Indonesian Equatorial LCO
			11 => 'indonesian_irian',       // Indonesian Irian LCO
			12 => 'indonesian_southern',    // Indonesian Southern LCO
			13 => 'india_zone_0',      // India zone 0
			14 => 'india_zone_IA',     // India zone IA
			15 => 'india_zone_IB',     // India zone IB
			16 => 'india_zone_IIA',    // India zone IIA
			17 => 'india_zone_IIB',    // India zone IIB
			18 => 'india_zone_IIIA',   // India zone IIIA
			19 => 'india_zone_IIIB',   // India zone IIIB
			20 => 'india_zone_IVA',    // India zone IVA
			21 => 'india_zone_IVB',    // India zone IVB
			22 => 'irish_transverse',  // Irish Transverse Mercator
			23 => 'irish_grid',        // Irish Grid
			24 => 'loran',             // Loran TD
			25 => 'maidenhead_grid',   // Maidenhead grid system
			26 => 'mgrs_grid',         // MGRS grid system
			27 => 'new_zealand_grid',  // New Zealand grid system
			28 => 'new_zealand_transverse',  // New Zealand Transverse Mercator
			29 => 'qatar_grid',              // Qatar National Grid
			30 => 'modified_swedish_grid',   // Modified RT-90 (Sweden)
			31 => 'swedish_grid',            // RT-90 (Sweden)
			32 => 'south_african_grid',      // South African Grid
			33 => 'swiss_grid',              // Swiss CH-1903 grid
			34 => 'taiwan_grid',             // Taiwan Grid
			35 => 'united_states_grid',      // United States National Grid
			36 => 'utm_ups_grid',            // UTM/UPS grid system
			37 => 'west_malayan',            // West Malayan RSO
			38 => 'borneo_rso',              // Borneo RSO
			39 => 'estonian_grid',           // Estonian grid system
			40 => 'latvian_grid',            // Latvian Transverse Mercator
			41 => 'swedish_ref_99_grid',     // Reference Grid 99 TM (Swedish)
		),
		'display_power'       => array(
			0 => 'watts',
			1 => 'percent_ftp',
		),
		'event'               => array(
			0  => 'timer',
			3  => 'workout',
			4  => 'workout_step',
			5  => 'power_down',
			6  => 'power_up',
			7  => 'off_course',
			8  => 'session',
			9  => 'lap',
			10 => 'course_point',
			11 => 'battery',
			12 => 'virtual_partner_pace',
			13 => 'hr_high_alert',
			14 => 'hr_low_alert',
			15 => 'speed_high_alert',
			16 => 'speed_low_alert',
			17 => 'cad_high_alert',
			18 => 'cad_low_alert',
			19 => 'power_high_alert',
			20 => 'power_low_alert',
			21 => 'recovery_hr',
			22 => 'battery_low',
			23 => 'time_duration_alert',
			24 => 'distance_duration_alert',
			25 => 'calorie_duration_alert',
			26 => 'activity',
			27 => 'fitness_equipment',
			28 => 'length',
			32 => 'user_marker',
			33 => 'sport_point',
			36 => 'calibration',
			42 => 'front_gear_change',
			43 => 'rear_gear_change',
			44 => 'rider_position_change',
			45 => 'elev_high_alert',
			46 => 'elev_low_alert',
			47 => 'comm_timeout',
		),
		'event_type'          => array(
			0 => 'start',
			1 => 'stop',
			2 => 'consecutive_depreciated',
			3 => 'marker',
			4 => 'stop_all',
			5 => 'begin_depreciated',
			6 => 'end_depreciated',
			7 => 'end_all_depreciated',
			8 => 'stop_disable',
			9 => 'stop_disable_all',
		),
		'file'                => array(
			1    => 'device',
			2    => 'settings',
			3    => 'sport',
			4    => 'activity',
			5    => 'workout',
			6    => 'course',
			7    => 'schedules',
			9    => 'weight',
			10   => 'totals',
			11   => 'goals',
			14   => 'blood_pressure',
			15   => 'monitoring_a',
			20   => 'activity_summary',
			28   => 'monitoring_daily',
			32   => 'monitoring_b',
			0xF7 => 'mfg_range_min',
			0xFE => 'mfg_range_max',
		),
		'gender'              => array(
			0 => 'female',
			1 => 'male',
		),
		'hr_zone_calc'        => array(
			0 => 'custom',
			1 => 'percent_max_hr',
			2 => 'percent_hrr',
		),
		'intensity'           => array(
			0 => 'active',
			1 => 'rest',
			2 => 'warmup',
			3 => 'cooldown',
		),
		'language'            => array(
			0   => 'english',
			1   => 'french',
			2   => 'italian',
			3   => 'german',
			4   => 'spanish',
			5   => 'croatian',
			6   => 'czech',
			7   => 'danish',
			8   => 'dutch',
			9   => 'finnish',
			10  => 'greek',
			11  => 'hungarian',
			12  => 'norwegian',
			13  => 'polish',
			14  => 'portuguese',
			15  => 'slovakian',
			16  => 'slovenian',
			17  => 'swedish',
			18  => 'russian',
			19  => 'turkish',
			20  => 'latvian',
			21  => 'ukrainian',
			22  => 'arabic',
			23  => 'farsi',
			24  => 'bulgarian',
			25  => 'romanian',
			254 => 'custom',
		),
		'length_type'         => array(
			0 => 'idle',
			1 => 'active',
		),
		'manufacturer'        => array(  // Have capitalised select manufacturers
			1    => 'Garmin',
			2    => 'garmin_fr405_antfs',
			3    => 'zephyr',
			4    => 'dayton',
			5    => 'idt',
			6    => 'SRM',
			7    => 'Quarq',
			8    => 'iBike',
			9    => 'saris',
			10   => 'spark_hk',
			11   => 'Tanita',
			12   => 'Echowell',
			13   => 'dynastream_oem',
			14   => 'nautilus',
			15   => 'dynastream',
			16   => 'Timex',
			17   => 'metrigear',
			18   => 'xelic',
			19   => 'beurer',
			20   => 'cardiosport',
			21   => 'a_and_d',
			22   => 'hmm',
			23   => 'Suunto',
			24   => 'thita_elektronik',
			25   => 'gpulse',
			26   => 'clean_mobile',
			27   => 'pedal_brain',
			28   => 'peaksware',
			29   => 'saxonar',
			30   => 'lemond_fitness',
			31   => 'dexcom',
			32   => 'Wahoo Fitness',
			33   => 'octane_fitness',
			34   => 'archinoetics',
			35   => 'the_hurt_box',
			36   => 'citizen_systems',
			37   => 'Magellan',
			38   => 'osynce',
			39   => 'holux',
			40   => 'concept2',
			42   => 'one_giant_leap',
			43   => 'ace_sensor',
			44   => 'brim_brothers',
			45   => 'xplova',
			46   => 'perception_digital',
			47   => 'bf1systems',
			48   => 'pioneer',
			49   => 'spantec',
			50   => 'metalogics',
			51   => '4iiiis',
			52   => 'seiko_epson',
			53   => 'seiko_epson_oem',
			54   => 'ifor_powell',
			55   => 'maxwell_guider',
			56   => 'star_trac',
			57   => 'breakaway',
			58   => 'alatech_technology_ltd',
			59   => 'mio_technology_europe',
			60   => 'Rotor',
			61   => 'geonaute',
			62   => 'id_bike',
			63   => 'Specialized',
			64   => 'wtek',
			65   => 'physical_enterprises',
			66   => 'north_pole_engineering',
			67   => 'BKOOL',
			68   => 'Cateye',
			69   => 'Stages Cycling',
			70   => 'Sigmasport',
			71   => 'TomTom',
			72   => 'peripedal',
			73   => 'Wattbike',
			76   => 'moxy',
			77   => 'ciclosport',
			78   => 'powerbahn',
			79   => 'acorn_projects_aps',
			80   => 'lifebeam',
			81   => 'Bontrager',
			82   => 'wellgo',
			83   => 'scosche',
			84   => 'magura',
			85   => 'woodway',
			86   => 'elite',
			87   => 'nielsen_kellerman',
			88   => 'dk_city',
			89   => 'Tacx',
			90   => 'direction_technology',
			91   => 'magtonic',
			92   => '1partcarbon',
			93   => 'inside_ride_technologies',
			94   => 'sound_of_motion',
			95   => 'stryd',
			96   => 'icg',
			97   => 'MiPulse',
			98   => 'bsx_athletics',
			99   => 'look',
			100  => 'campagnolo_srl',
			101  => 'body_bike_smart',
			102  => 'praxisworks',
			103  => 'limits_technology',
			104  => 'topaction_technology',
			105  => 'cosinuss',
			106  => 'fitcare',
			107  => 'magene',
			108  => 'giant_manufacturing_co',
			109  => 'tigrasport',
			110  => 'salutron',
			111  => 'technogym',
			112  => 'bryton_sensors',
			113  => 'latitude_limited',
			114  => 'soaring_technology',
			115  => 'igpsport',
			116  => 'thinkrider',
			117  => 'gopher_sport',
			118  => 'waterrower',
			119  => 'orangetheory',
			120  => 'inpeak',
			121  => 'kinetic',
			122  => 'johnson_health_tech',
			123  => 'polar_electro',
			124  => 'seesense',
			125  => 'nci_technology',
			126  => 'iqsquare',
			127  => 'leomo',
			128  => 'ifit_com',
			255  => 'development',
			257  => 'healthandlife',
			258  => 'Lezyne',
			259  => 'scribe_labs',
			260  => 'Zwift',
			261  => 'watteam',
			262  => 'recon',
			263  => 'favero_electronics',
			264  => 'dynovelo',
			265  => 'Strava',
			266  => 'precor',
			267  => 'Bryton',
			268  => 'sram',
			269  => 'navman',
			270  => 'cobi',
			271  => 'spivi',
			272  => 'mio_magellan',
			273  => 'evesports',
			274  => 'sensitivus_gauge',
			275  => 'podoon',
			276  => 'life_time_fitness',
			277  => 'falco_e_motors',
			278  => 'minoura',
			279  => 'cycliq',
			280  => 'luxottica',
			281  => 'trainer_road',
			282  => 'the_sufferfest',
			283  => 'fullspeedahead',
			284  => 'virtualtraining',
			285  => 'feedbacksports',
			286  => 'omata',
			287  => 'vdo',
			288  => 'magneticdays',
			289  => 'hammerhead',
			290  => 'kinetic_by_kurt',
			291  => 'shapelog',
			292  => 'dabuziduo',
			293  => 'jetblack',
			294  => 'coros',
			295  => 'virtugo',
			296  => 'velosense',
			297  => 'cycligentinc',
			298  => 'trailforks',
			299  => 'mahle_ebikemotion',
			5759 => 'actigraphcorp',
		),
		'pwr_zone_calc'       => array(
			0 => 'custom',
			1 => 'percent_ftp',
		),
		'product'             => array(  // Have formatted for devices known to use FIT format. (Original text commented-out).
			1     => 'hrm1',
			2     => 'axh01',
			3     => 'axb01',
			4     => 'axb02',
			5     => 'hrm2ss',
			6     => 'dsi_alf02',
			7     => 'hrm3ss',
			8     => 'hrm_run_single_byte_product_id',
			9     => 'bsm',
			10    => 'bcm',
			11    => 'axs01',
			12    => 'HRM-Tri',                    // 'hrm_tri_single_byte_product_id',
			14    => 'Forerunner 225',             // 'fr225_single_byte_product_id',
			473   => 'Forerunner 301',            // 'fr301_china',
			474   => 'Forerunner 301',            // 'fr301_japan',
			475   => 'Forerunner 301',            // 'fr301_korea',
			494   => 'Forerunner 301',            // 'fr301_taiwan',
			717   => 'Forerunner 405',            // 'fr405',
			782   => 'Forerunner 50',             // 'fr50',
			987   => 'Forerunner 405',            // 'fr405_japan',
			988   => 'Forerunner 60',             // 'fr60',
			1011  => 'dsi_alf01',
			1018  => 'Forerunner 310XT',         // 'fr310xt',
			1036  => 'Edge 500',                 // 'edge500',
			1124  => 'Forerunner 110',           // 'fr110',
			1169  => 'Edge 800',                 // 'edge800',
			1199  => 'Edge 500',                 // 'edge500_taiwan',
			1213  => 'Edge 500',                 // 'edge500_japan',
			1253  => 'chirp',
			1274  => 'Forerunner 110',           // 'fr110_japan',
			1325  => 'edge200',
			1328  => 'Forerunner 910XT',         // 'fr910xt',
			1333  => 'Edge 800',                 // 'edge800_taiwan',
			1334  => 'Edge 800',                 // 'edge800_japan',
			1341  => 'alf04',
			1345  => 'Forerunner 610',           // 'fr610',
			1360  => 'Forerunner 210',           // 'fr210_japan',
			1380  => 'Vector 2S',                // vector_ss
			1381  => 'Vector 2',                 // vector_cp
			1386  => 'Edge 800',                 // 'edge800_china',
			1387  => 'Edge 500',                 // 'edge500_china',
			1410  => 'Forerunner 610',           // 'fr610_japan',
			1422  => 'Edge 500',                 // 'edge500_korea',
			1436  => 'Forerunner 70',            // 'fr70',
			1446  => 'Forerunner 310XT',         // 'fr310xt_4t',
			1461  => 'amx',
			1482  => 'Forerunner 10',            // 'fr10',
			1497  => 'Edge 800',                 // 'edge800_korea',
			1499  => 'swim',
			1537  => 'Forerunner 910XT',         // 'fr910xt_china',
			1551  => 'Fenix',                    // fenix
			1555  => 'edge200_taiwan',
			1561  => 'Edge 510',                 // 'edge510',
			1567  => 'Edge 810',                 // 'edge810',
			1570  => 'tempe',
			1600  => 'Forerunner 910XT',         // 'fr910xt_japan',
			1623  => 'Forerunner 620',           // 'fr620',
			1632  => 'Forerunner 220',           // 'fr220',
			1664  => 'Forerunner 910XT',         // 'fr910xt_korea',
			1688  => 'Forerunner 10',            // 'fr10_japan',
			1721  => 'Edge 810',                 // 'edge810_japan',
			1735  => 'virb_elite',
			1736  => 'edge_touring',
			1742  => 'Edge 510',                 // 'edge510_japan',
			1743  => 'HRM-Tri',                  // 'hrm_tri',
			1752  => 'hrm_run',
			1765  => 'Forerunner 920XT',         // 'fr920xt',
			1821  => 'Edge 510',                 // 'edge510_asia',
			1822  => 'Edge 810',                 // 'edge810_china',
			1823  => 'Edge 810',                 // 'edge810_taiwan',
			1836  => 'Edge 1000',                // 'edge1000',
			1837  => 'vivo_fit',
			1853  => 'virb_remote',
			1885  => 'vivo_ki',
			1903  => 'Forerunner 15',            // 'fr15',
			1907  => 'vivoactive',               // 'vivo_active',
			1918  => 'Edge 510',                 // 'edge510_korea',
			1928  => 'Forerunner 620',           // 'fr620_japan',
			1929  => 'Forerunner 620',           // 'fr620_china',
			1930  => 'Forerunner 220',           // 'fr220_japan',
			1931  => 'Forerunner 220',           // 'fr220_china',
			1936  => 'Approach S6',              // 'approach_s6'
			1956  => 'vívosmart',                // 'vivo_smart',
			1967  => 'Fenix 2',                  // fenix2
			1988  => 'epix',
			2050  => 'Fenix 3',                  // fenix3
			2052  => 'Edge 1000',                // edge1000_taiwan
			2053  => 'Edge 1000',                // edge1000_japan
			2061  => 'Forerunner 15',            // fr15_japan
			2067  => 'Edge 520',                 // edge520
			2070  => 'Edge 1000',                // edge1000_china
			2072  => 'Forerunner 620',           // fr620_russia
			2073  => 'Forerunner 220',           // fr220_russia
			2079  => 'Vector S',                 // vector_s
			2100  => 'Edge 1000',                // edge1000_korea
			2130  => 'Forerunner 920',           // fr920xt_taiwan
			2131  => 'Forerunner 920',           // fr920xt_china
			2132  => 'Forerunner 920',           // fr920xt_japan
			2134  => 'virbx',
			2135  => 'vívosmart',                // vivo_smart_apac',
			2140  => 'etrex_touch',
			2147  => 'Edge 25',                  // edge25
			2148  => 'Forerunner 25',            // fr25
			2150  => 'vivo_fit2',
			2153  => 'Forerunner 225',           // fr225
			2156  => 'Forerunner 630',           // fr630
			2157  => 'Forerunner 230',           // fr230
			2160  => 'vivo_active_apac',
			2161  => 'Vector 2',                 // vector_2
			2162  => 'Vector 2S',                // vector_2s
			2172  => 'virbxe',
			2173  => 'Forerunner 620',           // fr620_taiwan
			2174  => 'Forerunner 220',           // fr220_taiwan
			2175  => 'truswing',
			2188  => 'Fenix 3',                  // fenix3_china
			2189  => 'Fenix 3',                  // fenix3_twn
			2192  => 'varia_headlight',
			2193  => 'varia_taillight_old',
			2204  => 'Edge Explore 1000',        // edge_explore_1000
			2219  => 'Forerunner 225',           // fr225_asia
			2225  => 'varia_radar_taillight',
			2226  => 'varia_radar_display',
			2238  => 'Edge 20',                  // edge20
			2262  => 'D2 Bravo',                 // d2_bravo
			2266  => 'approach_s20',
			2276  => 'varia_remote',
			2327  => 'hrm4_run',
			2337  => 'vivo_active_hr',
			2347  => 'vivo_smart_gps_hr',
			2348  => 'vivo_smart_hr',
			2368  => 'vivo_move',
			2398  => 'varia_vision',
			2406  => 'vivo_fit3',
			2413  => 'Fenix 3 HR',               // fenix3_hr
			2417  => 'Virb Ultra 30',            // virb_ultra_30
			2429  => 'index_smart_scale',
			2431  => 'Forerunner 235',           // fr235
			2432  => 'Fenix 3 Chronos',          // fenix3_chronos
			2441  => 'oregon7xx',
			2444  => 'rino7xx',
			2496  => 'nautix',
			2530  => 'Edge 820',                 // edge_820
			2531  => 'Edge Explore 820',         // edge_explore_820
			2544  => 'fenix5s',
			2547  => 'D2 Bravo Titanium',        // d2_bravo_titanium
			2593  => 'Running Dynamics Pod',     // running_dynamics_pod
			2604  => 'Fenix 5x',                 // fenix5x
			2606  => 'vivofit jr',               // vivo_fit_jr
			2691  => 'Forerunner 935',           // fr935
			2697  => 'Fenix 5',                  // fenix5
			2700  => 'vivoactive3',
			2769  => 'foretrex_601_701',
			2772  => 'vivo_move_hr',
			2713  => 'Edge 1030',                // edge_1030
			2806  => 'approach_z80',
			2831  => 'vivo_smart3_apac',
			2832  => 'vivo_sport_apac',
			2859  => 'descent',
			2886  => 'Forerunner 645',           // fr645
			2888  => 'Forerunner 645',           // fr645m
			2900  => 'Fenix 5S Plus',            // fenix5s_plus
			2909  => 'Edge 130',                 // Edge_130
			2927  => 'vivosmart_4',
			2962  => 'approach_x10',
			2988  => 'vivoactive3m_w',
			3011  => 'edge_explore',
			3028  => 'gpsmap66',
			3049  => 'approach_s10',
			3066  => 'vivoactive3m_l',
			3085  => 'approach_g80',
			3110  => 'Fenix 5 Plus',             // fenix5_plus
			3111  => 'Fenix 5X Plus',            // fenix5x_plus
			3112  => 'Edge 520 Plus',            // edge_520_plus
			3299  => 'hrm_dual',
			3314  => 'approach_s40',
			10007 => 'SDM4 footpod',            // sdm4
			10014 => 'edge_remote',
			20119 => 'training_center',
			65531 => 'connectiq_simulator',
			65532 => 'android_antplus_plugin',
			65534 => 'Garmin Connect website',   // connect
		),
		'sport'               => array(  // Have capitalised and replaced underscores with spaces.
			0   => 'Other',
			1   => 'Running',
			2   => 'Cycling',
			3   => 'Transition',
			4   => 'Fitness equipment',
			5   => 'Swimming',
			6   => 'Basketball',
			7   => 'Soccer',
			8   => 'Tennis',
			9   => 'American football',
			10  => 'Training',
			11  => 'Walking',
			12  => 'Cross country skiing',
			13  => 'Alpine skiing',
			14  => 'Snowboarding',
			15  => 'Rowing',
			16  => 'Mountaineering',
			17  => 'Hiking',
			18  => 'Multisport',
			19  => 'Paddling',
			20  => 'Flying',
			21  => 'E-Biking',
			22  => 'Motorcycling',
			23  => 'Boating',
			24  => 'Diving',
			25  => 'Golf',
			26  => 'Hang gliding',
			27  => 'Horseback riding',
			28  => 'Hunting',
			29  => 'Fishing',
			30  => 'Inline skating',
			31  => 'Rock climbing',
			32  => 'Sailing',
			33  => 'Ice skating',
			34  => 'Sky diving',
			35  => 'Snowshoeing',
			36  => 'Snowmobiling',
			37  => 'Stand up paddleboarding',
			38  => 'Surfing',
			39  => 'Wakeboarding',
			40  => 'Water skiing',
			41  => 'Kayaking',
			42  => 'Rafting',
			43  => 'Windsurfing',
			44  => 'Kitesurfing',
			45  => 'Tactical',
			46  => 'Jumpmaster',
			47  => 'Boxing',
			48  => 'Floor climbing',
			53  => 'Diving',
			62  => 'HIIT',
			64  => 'Racket',
			65  => 'Wheelchair push walk',
			66  => 'Wheelchair push run',
			67  => 'Meditation',
			76  => 'Water tubing',
			77  => 'Wakesurfing',
			254 => 'All',
		),
		'sub_sport'           => array(  // Have capitalised and replaced underscores with spaces.
			0   => 'Other',
			1   => 'Treadmill',
			2   => 'Street',
			3   => 'Trail',
			4   => 'Track',
			5   => 'Spin',
			6   => 'Indoor cycling',
			7   => 'Road',
			8   => 'Mountain',
			9   => 'Downhill',
			10  => 'Recumbent',
			11  => 'Cyclocross',
			12  => 'Hand cycling',
			13  => 'Track cycling',
			14  => 'Indoor rowing',
			15  => 'Elliptical',
			16  => 'Stair climbing',
			17  => 'Lap swimming',
			18  => 'Open water',
			19  => 'Flexibility training',
			20  => 'Strength training',
			21  => 'Warm up',
			22  => 'Match',
			23  => 'Exercise',
			24  => 'Challenge',
			25  => 'Indoor skiing',
			26  => 'Cardio training',
			27  => 'Indoor walking',
			28  => 'E-Bike Fitness',
			29  => 'BMX',
			30  => 'Casual walking',
			31  => 'Speed walking',
			32  => 'Bike to run transition',
			33  => 'Run to bike transition',
			34  => 'Swim to bike transition',
			35  => 'ATV',
			36  => 'Motocross',
			37  => 'Backcountry skiing',
			38  => 'Resort skiing',
			39  => 'RC Drone',
			40  => 'Wingsuit',
			41  => 'Whitewater Kayaking',
			42  => 'Skate skiing',
			43  => 'Yoga',
			44  => 'Pilates',
			45  => 'Indoor running',
			46  => 'Gravel cycling',
			47  => 'Mountain Ebike',
			48  => 'Commuting cycling',
			49  => 'Mixed surface cycling',
			50  => 'Navigate',
			51  => 'Track me',
			52  => 'Map',
			53  => 'Single gas diving',
			54  => 'Multi gas diving',
			55  => 'Gauge diving',
			56  => 'Apnea diving',
			57  => 'Apnea hunting',
			58  => 'Virtual activity',
			59  => 'Obstacle',
			62  => 'Breathing',
			65  => 'Sail race',
			67  => 'Ultra',
			68  => 'Indoor climbing',
			69  => 'Bouldering',
			70  => 'Hiit',
			73  => 'Amrap',
			74  => 'Emom',
			75  => 'Tabata',
			84  => 'Pickleball',
			85  => 'Padel',
			86  => 'Indoor wheelchair walk',
			87  => 'Indoor wheelchair run',
			88  => 'Indoor hand cycling',
			110 => 'Fly canopy',
			111 => 'Fly paraglide',
			112 => 'Fly paramotor',
			113 => 'Fly pressurized',
			114 => 'Fly navigate',
			115 => 'Fly timer',
			116 => 'Fly altimeter',
			117 => 'Fly wx',
			118 => 'Fly vfr',
			119 => 'Fly ifr',
			254 => 'All',
		),
		'session_trigger'     => array(
			0 => 'activity_end',
			1 => 'manual',
			2 => 'auto_multi_sport',
			3 => 'fitness_equipment',
		),
		'source_type'         => array(
			0 => 'ant',  // External device connected with ANT
			1 => 'antplus',  // External device connected with ANT+
			2 => 'bluetooth',  // External device connected with BT
			3 => 'bluetooth_low_energy',  // External device connected with BLE
			4 => 'wifi',  // External device connected with Wifi
			5 => 'local',  // Onboard device
		),
		'swim_stroke'         => array(
			0 => 'Freestyle',
			1 => 'Backstroke',
			2 => 'Breaststroke',
			3 => 'Butterfly',
			4 => 'Drill',
			5 => 'Mixed',
			6 => 'IM',
		),  // Have capitalised.
		'water_type'          => array(
			0 => 'fresh',
			1 => 'salt',
			2 => 'en13319',
			3 => 'custom',
		),
		'tissue_model_type'   => array( 0 => 'zhl_16c' ),
		'dive_gas_status'     => array(
			0 => 'disabled',
			1 => 'enabled',
			2 => 'backup_only',
		),
		'dive_alarm_type'     => array(
			0 => 'depth',
			1 => 'time',
		),
		'dive_backlight_mode' => array(
			0 => 'at_depth',
			1 => 'always_on',
		),
	);

	/**
	 * D00001275 Flexible & Interoperable Data Transfer (FIT) Protocol Rev 2.2.pdf
	 * Table 4-6. FIT Base Types and Invalid Values
	 *
	 * $types array holds a string used by the PHP unpack() function to format binary data.
	 * 'tmp' is the name of the (single element) array created.
	 */
	private $endianness = array(
		0 => array(  // Little Endianness
			0   => array(
				'format' => 'Ctmp',
				'bytes'  => 1,
			),  // enum
			1   => array(
				'format' => 'ctmp',
				'bytes'  => 1,
			),  // sint8
			2   => array(
				'format' => 'Ctmp',
				'bytes'  => 1,
			),  // uint8
			131 => array(
				'format' => 'vtmp',
				'bytes'  => 2,
			),  // sint16 - manually convert uint16 to sint16 in fixData()
			132 => array(
				'format' => 'vtmp',
				'bytes'  => 2,
			),  // uint16
			133 => array(
				'format' => 'Vtmp',
				'bytes'  => 4,
			),  // sint32 - manually convert uint32 to sint32 in fixData()
			134 => array(
				'format' => 'Vtmp',
				'bytes'  => 4,
			),  // uint32
			7   => array(
				'format' => 'a*tmp',
				'bytes'  => 1,
			), // string
			136 => array(
				'format' => 'ftmp',
				'bytes'  => 4,
			),  // float32
			137 => array(
				'format' => 'dtmp',
				'bytes'  => 8,
			),  // float64
			10  => array(
				'format' => 'Ctmp',
				'bytes'  => 1,
			),  // uint8z
			139 => array(
				'format' => 'vtmp',
				'bytes'  => 2,
			),  // uint16z
			140 => array(
				'format' => 'Vtmp',
				'bytes'  => 4,
			),  // uint32z
			13  => array(
				'format' => 'Ctmp',
				'bytes'  => 1,
			),  // byte
			142 => array(
				'format' => 'Ptmp',
				'bytes'  => 8,
			),  // sint64 - manually convert uint64 to sint64 in fixData()
			143 => array(
				'format' => 'Ptmp',
				'bytes'  => 8,
			),  // uint64
			144 => array(
				'format' => 'Ptmp',
				'bytes'  => 8,
			),   // uint64z
		),
		1 => array(  // Big Endianness
			0   => array(
				'format' => 'Ctmp',
				'bytes'  => 1,
			),  // enum
			1   => array(
				'format' => 'ctmp',
				'bytes'  => 1,
			),  // sint8
			2   => array(
				'format' => 'Ctmp',
				'bytes'  => 1,
			),  // uint8
			131 => array(
				'format' => 'ntmp',
				'bytes'  => 2,
			),  // sint16 - manually convert uint16 to sint16 in fixData()
			132 => array(
				'format' => 'ntmp',
				'bytes'  => 2,
			),  // uint16
			133 => array(
				'format' => 'Ntmp',
				'bytes'  => 4,
			),  // sint32 - manually convert uint32 to sint32 in fixData()
			134 => array(
				'format' => 'Ntmp',
				'bytes'  => 4,
			),  // uint32
			7   => array(
				'format' => 'a*tmp',
				'bytes'  => 1,
			), // string
			136 => array(
				'format' => 'ftmp',
				'bytes'  => 4,
			),  // float32
			137 => array(
				'format' => 'dtmp',
				'bytes'  => 8,
			),  // float64
			10  => array(
				'format' => 'Ctmp',
				'bytes'  => 1,
			),  // uint8z
			139 => array(
				'format' => 'ntmp',
				'bytes'  => 2,
			),  // uint16z
			140 => array(
				'format' => 'Ntmp',
				'bytes'  => 4,
			),  // uint32z
			13  => array(
				'format' => 'Ctmp',
				'bytes'  => 1,
			),  // byte
			142 => array(
				'format' => 'Jtmp',
				'bytes'  => 8,
			),  // sint64 - manually convert uint64 to sint64 in fixData()
			143 => array(
				'format' => 'Jtmp',
				'bytes'  => 8,
			),  // uint64
			144 => array(
				'format' => 'Jtmp',
				'bytes'  => 8,
			),   // uint64z
		),
	);

	private $invalid_values = array(
		0   => 255,                  // 0xFF
		1   => 127,                  // 0x7F
		2   => 255,                  // 0xFF
		131 => 32767,                // 0x7FFF
		132 => 65535,                // 0xFFFF
		133 => 2147483647,           // 0x7FFFFFFF
		134 => 4294967295,           // 0xFFFFFFFF
		7   => 0,                    // 0x00
		136 => 4294967295,           // 0xFFFFFFFF
		137 => 9223372036854775807,  // 0xFFFFFFFFFFFFFFFF
		10  => 0,                    // 0x00
		139 => 0,                    // 0x0000
		140 => 0,                    // 0x00000000
		13  => 255,                  // 0xFF
		142 => 9223372036854775807,  // 0x7FFFFFFFFFFFFFFF
		143 => 18446744073709551615, // 0xFFFFFFFFFFFFFFFF
		144 => 0,                     // 0x0000000000000000
	);

	/**
	 * D00001275 Flexible & Interoperable Data Transfer (FIT) Protocol Rev 1.7.pdf
	 * 4.4 Scale/Offset
	 * When specified, the binary quantity is divided by the scale factor and then the offset is subtracted, yielding a floating point quantity.
	 */
	private $data_mesg_info = array(
		0   => array(
			'mesg_name'   => 'file_id',
			'field_defns' => array(
				0 => array(
					'field_name' => 'type',
					'scale'      => 1,
					'offset'     => 0,
					'units'      => '',
					'raw'        => 'TINYINT UNSIGNED',
					'metric'     => 'TINYINT UNSIGNED',
					'statute'    => 'TINYINT UNSIGNED',
				),
				1 => array(
					'field_name' => 'manufacturer',
					'scale'      => 1,
					'offset'     => 0,
					'units'      => '',
					'raw'        => 'SMALLINT UNSIGNED',
					'metric'     => 'SMALLINT UNSIGNED',
					'statute'    => 'SMALLINT UNSIGNED',
				),
				2 => array(
					'field_name' => 'product',
					'scale'      => 1,
					'offset'     => 0,
					'units'      => '',
					'raw'        => 'SMALLINT UNSIGNED',
					'metric'     => 'SMALLINT UNSIGNED',
					'statute'    => 'SMALLINT UNSIGNED',
				),
				3 => array(
					'field_name' => 'serial_number',
					'scale'      => 1,
					'offset'     => 0,
					'units'      => '',
					'raw'        => 'INT UNSIGNED',
					'metric'     => 'INT UNSIGNED',
					'statute'    => 'INT UNSIGNED',
				),
				4 => array(
					'field_name' => 'time_created',
					'scale'      => 1,
					'offset'     => 0,
					'units'      => '',
					'raw'        => 'INT UNSIGNED',
					'metric'     => 'INT UNSIGNED',
					'statute'    => 'INT UNSIGNED',
				),
				5 => array(
					'field_name' => 'number',
					'scale'      => 1,
					'offset'     => 0,
					'units'      => '',
					'raw'        => 'SMALLINT UNSIGNED',
					'metric'     => 'SMALLINT UNSIGNED',
					'statute'    => 'SMALLINT UNSIGNED',
				),
			),
		),

		2   => array(
			'mesg_name'   => 'device_settings',
			'field_defns' => array(
				0 => array(
					'field_name' => 'active_time_zone',
					'scale'      => 1,
					'offset'     => 0,
					'units'      => '',
					'raw'        => 'TINYINT UNSIGNED',
					'metric'     => 'TINYINT UNSIGNED',
					'statute'    => 'TINYINT UNSIGNED',
				),
				1 => array(
					'field_name' => 'utc_offset',
					'scale'      => 1,
					'offset'     => 0,
					'units'      => '',
					'raw'        => 'INT UNSIGNED',
					'metric'     => 'INT UNSIGNED',
					'statute'    => 'INT UNSIGNED',
				),
				5 => array(
					'field_name' => 'time_zone_offset',
					'scale'      => 4,
					'offset'     => 0,
					'units'      => 'hr',
					'raw'        => 'INT UNSIGNED',
					'metric'     => 'INT UNSIGNED',
					'statute'    => 'INT UNSIGNED',
				),
			),
		),

		3   => array(
			'mesg_name'   => 'user_profile',
			'field_defns' => array(
				0  => array(
					'field_name' => 'friendly_name',
					'scale'      => 1,
					'offset'     => 0,
					'units'      => '',
					'raw'        => 'VARCHAR(256)',
					'metric'     => 'VARCHAR(256)',
					'statute'    => 'VARCHAR(256)',
				),
				1  => array(
					'field_name' => 'gender',
					'scale'      => 1,
					'offset'     => 0,
					'units'      => '',
					'raw'        => 'TINYINT(1)',
					'metric'     => 'TINYINT(1)',
					'statute'    => 'TINYINT(1)',
				),
				2  => array(
					'field_name' => 'age',
					'scale'      => 1,
					'offset'     => 0,
					'units'      => 'years',
					'raw'        => 'TINYINT UNSIGNED',
					'metric'     => 'TINYINT UNSIGNED',
					'statute'    => 'TINYINT UNSIGNED',
				),
				3  => array(
					'field_name' => 'height',
					'scale'      => 100,
					'offset'     => 0,
					'units'      => 'm',
					'raw'        => 'DECIMAL(3,2)',
					'metric'     => 'DECIMAL(3,2)',
					'statute'    => 'DECIMAL(3,2)',
				),
				4  => array(
					'field_name' => 'weight',
					'scale'      => 10,
					'offset'     => 0,
					'units'      => 'kg',
					'raw'        => 'DECIMAL(5,1)',
					'metric'     => 'DECIMAL(5,1)',
					'statute'    => 'DECIMAL(5,1)',
				),
				5  => array(
					'field_name' => 'language',
					'scale'      => 1,
					'offset'     => 0,
					'units'      => '',
					'raw'        => 'TINYINT UNSIGNED',
					'metric'     => 'TINYINT UNSIGNED',
					'statute'    => 'TINYINT UNSIGNED',
				),
				6  => array(
					'field_name' => 'elev_setting',
					'scale'      => 1,
					'offset'     => 0,
					'units'      => '',
					'raw'        => 'TINYINT UNSIGNED',
					'metric'     => 'TINYINT UNSIGNED',
					'statute'    => 'TINYINT UNSIGNED',
				),
				7  => array(
					'field_name' => 'weight_setting',
					'scale'      => 1,
					'offset'     => 0,
					'units'      => '',
					'raw'        => 'TINYINT UNSIGNED',
					'metric'     => 'TINYINT UNSIGNED',
					'statute'    => 'TINYINT UNSIGNED',
				),
				8  => array(
					'field_name' => 'resting_heart_rate',
					'scale'      => 1,
					'offset'     => 0,
					'units'      => 'bpm',
					'raw'        => 'TINYINT UNSIGNED',
					'metric'     => 'TINYINT UNSIGNED',
					'statute'    => 'TINYINT UNSIGNED',
				),
				9  => array(
					'field_name' => 'default_max_running_heart_rate',
					'scale'      => 1,
					'offset'     => 0,
					'units'      => 'bpm',
					'raw'        => 'TINYINT UNSIGNED',
					'metric'     => 'TINYINT UNSIGNED',
					'statute'    => 'TINYINT UNSIGNED',
				),
				10 => array(
					'field_name' => 'default_max_biking_heart_rate',
					'scale'      => 1,
					'offset'     => 0,
					'units'      => 'bpm',
					'raw'        => 'TINYINT UNSIGNED',
					'metric'     => 'TINYINT UNSIGNED',
					'statute'    => 'TINYINT UNSIGNED',
				),
				11 => array(
					'field_name' => 'default_max_heart_rate',
					'scale'      => 1,
					'offset'     => 0,
					'units'      => 'bpm',
					'raw'        => 'TINYINT UNSIGNED',
					'metric'     => 'TINYINT UNSIGNED',
					'statute'    => 'TINYINT UNSIGNED',
				),
				12 => array(
					'field_name' => 'hr_setting',
					'scale'      => 1,
					'offset'     => 0,
					'units'      => '',
					'raw'        => 'TINYINT UNSIGNED',
					'metric'     => 'TINYINT UNSIGNED',
					'statute'    => 'TINYINT UNSIGNED',
				),
				13 => array(
					'field_name' => 'speed_setting',
					'scale'      => 1,
					'offset'     => 0,
					'units'      => '',
					'raw'        => 'TINYINT UNSIGNED',
					'metric'     => 'TINYINT UNSIGNED',
					'statute'    => 'TINYINT UNSIGNED',
				),
				14 => array(
					'field_name' => 'dist_setting',
					'scale'      => 1,
					'offset'     => 0,
					'units'      => '',
					'raw'        => 'TINYINT UNSIGNED',
					'metric'     => 'TINYINT UNSIGNED',
					'statute'    => 'TINYINT UNSIGNED',
				),
				16 => array(
					'field_name' => 'power_setting',
					'scale'      => 1,
					'offset'     => 0,
					'units'      => '',
					'raw'        => 'TINYINT UNSIGNED',
					'metric'     => 'TINYINT UNSIGNED',
					'statute'    => 'TINYINT UNSIGNED',
				),
				17 => array(
					'field_name' => 'activity_class',
					'scale'      => 1,
					'offset'     => 0,
					'units'      => '',
					'raw'        => 'TINYINT UNSIGNED',
					'metric'     => 'TINYINT UNSIGNED',
					'statute'    => 'TINYINT UNSIGNED',
				),
				18 => array(
					'field_name' => 'position_setting',
					'scale'      => 1,
					'offset'     => 0,
					'units'      => '',
					'raw'        => 'TINYINT UNSIGNED',
					'metric'     => 'TINYINT UNSIGNED',
					'statute'    => 'TINYINT UNSIGNED',
				),
				21 => array(
					'field_name' => 'temperature_setting',
					'scale'      => 1,
					'offset'     => 0,
					'units'      => '',
					'raw'        => 'TINYINT UNSIGNED',
					'metric'     => 'TINYINT UNSIGNED',
					'statute'    => 'TINYINT UNSIGNED',
				),
			),
		),

		7   => array(
			'mesg_name'   => 'zones_target',
			'field_defns' => array(
				1 => array(
					'field_name' => 'max_heart_rate',
					'scale'      => 1,
					'offset'     => 0,
					'units'      => '',
					'raw'        => 'TINYINT UNSIGNED',
					'metric'     => 'TINYINT UNSIGNED',
					'statute'    => 'TINYINT UNSIGNED',
				),
				2 => array(
					'field_name' => 'threshold_heart_rate',
					'scale'      => 1,
					'offset'     => 0,
					'units'      => '',
					'raw'        => 'TINYINT UNSIGNED',
					'metric'     => 'TINYINT UNSIGNED',
					'statute'    => 'TINYINT UNSIGNED',
				),
				3 => array(
					'field_name' => 'functional_threshold_power',
					'scale'      => 1,
					'offset'     => 0,
					'units'      => '',
					'raw'        => 'SMALLINT UNSIGNED',
					'metric'     => 'SMALLINT UNSIGNED',
					'statute'    => 'SMALLINT UNSIGNED',
				),
				5 => array(
					'field_name' => 'hr_calc_type',
					'scale'      => 1,
					'offset'     => 0,
					'units'      => '',
					'raw'        => 'TINYINT UNSIGNED',
					'metric'     => 'TINYINT UNSIGNED',
					'statute'    => 'TINYINT UNSIGNED',
				),
				7 => array(
					'field_name' => 'pwr_calc_type',
					'scale'      => 1,
					'offset'     => 0,
					'units'      => '',
					'raw'        => 'TINYINT UNSIGNED',
					'metric'     => 'TINYINT UNSIGNED',
					'statute'    => 'TINYINT UNSIGNED',
				),
			),
		),

		12  => array(
			'mesg_name'   => 'sport',
			'field_defns' => array(
				0 => array(
					'field_name' => 'sport',
					'scale'      => 1,
					'offset'     => 0,
					'units'      => '',
					'raw'        => 'TINYINT UNSIGNED',
					'metric'     => 'TINYINT UNSIGNED',
					'statute'    => 'TINYINT UNSIGNED',
				),
				1 => array(
					'field_name' => 'sub_sport',
					'scale'      => 1,
					'offset'     => 0,
					'units'      => '',
					'raw'        => 'TINYINT UNSIGNED',
					'metric'     => 'TINYINT UNSIGNED',
					'statute'    => 'TINYINT UNSIGNED',
				),
				3 => array(
					'field_name' => 'name',
					'scale'      => 1,
					'offset'     => 0,
					'units'      => '',
					'raw'        => 'VARCHAR(33)',
					'metric'     => 'VARCHAR(33)',
					'statute'    => 'VARCHAR(33)',
				),
			),
		),

		18  => array(
			'mesg_name'   => 'session',
			'field_defns' => array(
				0   => array(
					'field_name' => 'event',
					'scale'      => 1,
					'offset'     => 0,
					'units'      => '',
					'raw'        => 'TINYINT UNSIGNED',
					'metric'     => 'TINYINT UNSIGNED',
					'statute'    => 'TINYINT UNSIGNED',
				),
				1   => array(
					'field_name' => 'event_type',
					'scale'      => 1,
					'offset'     => 0,
					'units'      => '',
					'raw'        => 'TINYINT UNSIGNED',
					'metric'     => 'TINYINT UNSIGNED',
					'statute'    => 'TINYINT UNSIGNED',
				),
				2   => array(
					'field_name' => 'start_time',
					'scale'      => 1,
					'offset'     => 0,
					'units'      => '',
					'raw'        => 'INT UNSIGNED',
					'metric'     => 'INT UNSIGNED',
					'statute'    => 'INT UNSIGNED',
				),
				3   => array(
					'field_name' => 'start_position_lat',
					'scale'      => 1,
					'offset'     => 0,
					'units'      => 'semicircles',
					'raw'        => 'INT',
					'metric'     => 'DECIMAL(10,7)',
					'statute'    => 'DECIMAL(10,7)',
				),
				4   => array(
					'field_name' => 'start_position_long',
					'scale'      => 1,
					'offset'     => 0,
					'units'      => 'semicircles',
					'raw'        => 'INT',
					'metric'     => 'DECIMAL(11,7)',
					'statute'    => 'DECIMAL(11,7)',
				),
				5   => array(
					'field_name' => 'sport',
					'scale'      => 1,
					'offset'     => 0,
					'units'      => '',
					'raw'        => 'TINYINT UNSIGNED',
					'metric'     => 'TINYINT UNSIGNED',
					'statute'    => 'TINYINT UNSIGNED',
				),
				6   => array(
					'field_name' => 'sub_sport',
					'scale'      => 1,
					'offset'     => 0,
					'units'      => '',
					'raw'        => 'TINYINT UNSIGNED',
					'metric'     => 'TINYINT UNSIGNED',
					'statute'    => 'TINYINT UNSIGNED',
				),
				7   => array(
					'field_name' => 'total_elapsed_time',
					'scale'      => 1000,
					'offset'     => 0,
					'units'      => 's',
					'raw'        => 'DECIMAL(10,3)',
					'metric'     => 'DECIMAL(10,3)',
					'statute'    => 'DECIMAL(10,3)',
				),
				8   => array(
					'field_name' => 'total_timer_time',
					'scale'      => 1000,
					'offset'     => 0,
					'units'      => 's',
					'raw'        => 'DECIMAL(10,3)',
					'metric'     => 'DECIMAL(10,3)',
					'statute'    => 'DECIMAL(10,3)',
				),
				9   => array(
					'field_name' => 'total_distance',
					'scale'      => 100,
					'offset'     => 0,
					'units'      => 'm',
					'raw'        => 'DECIMAL(10,2)',
					'metric'     => 'DECIMAL(10,5)',
					'statute'    => 'DECIMAL(10,5)',
				),
				10  => array(
					'field_name' => 'total_cycles',
					'scale'      => 1,
					'offset'     => 0,
					'units'      => 'cycles',
					'raw'        => 'INT UNSIGNED',
					'metric'     => 'INT UNSIGNED',
					'statute'    => 'INT UNSIGNED',
				),
				11  => array(
					'field_name' => 'total_calories',
					'scale'      => 1,
					'offset'     => 0,
					'units'      => 'kcal',
					'raw'        => 'SMALLINT UNSIGNED',
					'metric'     => 'SMALLINT UNSIGNED',
					'statute'    => 'SMALLINT UNSIGNED',
				),
				13  => array(
					'field_name' => 'total_fat_calories',
					'scale'      => 1,
					'offset'     => 0,
					'units'      => 'kcal',
					'raw'        => 'SMALLINT UNSIGNED',
					'metric'     => 'SMALLINT UNSIGNED',
					'statute'    => 'SMALLINT UNSIGNED',
				),
				14  => array(
					'field_name' => 'avg_speed',
					'scale'      => 1000,
					'offset'     => 0,
					'units'      => 'm/s',
					'raw'        => 'DECIMAL(5,3)',
					'metric'     => 'DECIMAL(7,4)',
					'statute'    => 'DECIMAL(7,4)',
				),
				15  => array(
					'field_name' => 'max_speed',
					'scale'      => 1000,
					'offset'     => 0,
					'units'      => 'm/s',
					'raw'        => 'DECIMAL(5,3)',
					'metric'     => 'DECIMAL(7,4)',
					'statute'    => 'DECIMAL(7,4)',
				),
				16  => array(
					'field_name' => 'avg_heart_rate',
					'scale'      => 1,
					'offset'     => 0,
					'units'      => 'bpm',
					'raw'        => 'TINYINT UNSIGNED',
					'metric'     => 'TINYINT UNSIGNED',
					'statute'    => 'TINYINT UNSIGNED',
				),
				17  => array(
					'field_name' => 'max_heart_rate',
					'scale'      => 1,
					'offset'     => 0,
					'units'      => 'bpm',
					'raw'        => 'TINYINT UNSIGNED',
					'metric'     => 'TINYINT UNSIGNED',
					'statute'    => 'TINYINT UNSIGNED',
				),
				18  => array(
					'field_name' => 'avg_cadence',
					'scale'      => 1,
					'offset'     => 0,
					'units'      => 'rpm',
					'raw'        => 'TINYINT UNSIGNED',
					'metric'     => 'TINYINT UNSIGNED',
					'statute'    => 'TINYINT UNSIGNED',
				),
				19  => array(
					'field_name' => 'max_cadence',
					'scale'      => 1,
					'offset'     => 0,
					'units'      => 'rpm',
					'raw'        => 'TINYINT UNSIGNED',
					'metric'     => 'TINYINT UNSIGNED',
					'statute'    => 'TINYINT UNSIGNED',
				),
				20  => array(
					'field_name' => 'avg_power',
					'scale'      => 1,
					'offset'     => 0,
					'units'      => 'watts',
					'raw'        => 'SMALLINT UNSIGNED',
					'metric'     => 'SMALLINT UNSIGNED',
					'statute'    => 'SMALLINT UNSIGNED',
				),
				21  => array(
					'field_name' => 'max_power',
					'scale'      => 1,
					'offset'     => 0,
					'units'      => 'watts',
					'raw'        => 'SMALLINT UNSIGNED',
					'metric'     => 'SMALLINT UNSIGNED',
					'statute'    => 'SMALLINT UNSIGNED',
				),
				22  => array(
					'field_name' => 'total_ascent',
					'scale'      => 1,
					'offset'     => 0,
					'units'      => 'm',
					'raw'        => 'SMALLINT UNSIGNED',
					'metric'     => 'SMALLINT UNSIGNED',
					'statute'    => 'DECIMAL(7,1)',
				),
				23  => array(
					'field_name' => 'total_descent',
					'scale'      => 1,
					'offset'     => 0,
					'units'      => 'm',
					'raw'        => 'SMALLINT UNSIGNED',
					'metric'     => 'SMALLINT UNSIGNED',
					'statute'    => 'DECIMAL(7,1)',
				),
				24  => array(
					'field_name' => 'total_training_effect',
					'scale'      => 10,
					'offset'     => 0,
					'units'      => '',
					'raw'        => 'DECIMAL(3,1)',
					'metric'     => 'DECIMAL(3,1)',
					'statute'    => 'DECIMAL(3,1)',
				),
				25  => array(
					'field_name' => 'first_lap_index',
					'scale'      => 1,
					'offset'     => 0,
					'units'      => '',
					'raw'        => 'SMALLINT UNSIGNED',
					'metric'     => 'SMALLINT UNSIGNED',
					'statute'    => 'SMALLINT UNSIGNED',
				),
				26  => array(
					'field_name' => 'num_laps',
					'scale'      => 1,
					'offset'     => 0,
					'units'      => '',
					'raw'        => 'SMALLINT UNSIGNED',
					'metric'     => 'SMALLINT UNSIGNED',
					'statute'    => 'SMALLINT UNSIGNED',
				),
				27  => array(
					'field_name' => 'event_group',
					'scale'      => 1,
					'offset'     => 0,
					'units'      => '',
					'raw'        => 'TINYINT UNSIGNED',
					'metric'     => 'TINYINT UNSIGNED',
					'statute'    => 'TINYINT UNSIGNED',
				),
				28  => array(
					'field_name' => 'trigger',
					'scale'      => 1,
					'offset'     => 0,
					'units'      => '',
					'raw'        => 'TINYINT UNSIGNED',
					'metric'     => 'TINYINT UNSIGNED',
					'statute'    => 'TINYINT UNSIGNED',
				),
				29  => array(
					'field_name' => 'nec_lat',
					'scale'      => 1,
					'offset'     => 0,
					'units'      => 'semicircles',
					'raw'        => 'INT',
					'metric'     => 'DECIMAL(10,7)',
					'statute'    => 'DECIMAL(10,7)',
				),
				30  => array(
					'field_name' => 'nec_long',
					'scale'      => 1,
					'offset'     => 0,
					'units'      => 'semicircles',
					'raw'        => 'INT',
					'metric'     => 'DECIMAL(11,7)',
					'statute'    => 'DECIMAL(11,7)',
				),
				31  => array(
					'field_name' => 'swc_lat',
					'scale'      => 1,
					'offset'     => 0,
					'units'      => 'semicircles',
					'raw'        => 'INT',
					'metric'     => 'DECIMAL(10,7)',
					'statute'    => 'DECIMAL(10,7)',
				),
				32  => array(
					'field_name' => 'swc_long',
					'scale'      => 1,
					'offset'     => 0,
					'units'      => 'semicircles',
					'raw'        => 'INT',
					'metric'     => 'DECIMAL(11,7)',
					'statute'    => 'DECIMAL(11,7)',
				),
				34  => array(
					'field_name' => 'normalized_power',
					'scale'      => 1,
					'offset'     => 0,
					'units'      => 'watts',
					'raw'        => 'SMALLINT UNSIGNED',
					'metric'     => 'SMALLINT UNSIGNED',
					'statute'    => 'SMALLINT UNSIGNED',
				),
				35  => array(
					'field_name' => 'training_stress_score',
					'scale'      => 10,
					'offset'     => 0,
					'units'      => 'tss',
					'raw'        => 'DECIMAL(5,1)',
					'metric'     => 'DECIMAL(5,1)',
					'statute'    => 'DECIMAL(5,1)',
				),
				36  => array(
					'field_name' => 'intensity_factor',
					'scale'      => 1000,
					'offset'     => 0,
					'units'      => 'if',
					'raw'        => 'DECIMAL(5,3)',
					'metric'     => 'DECIMAL(5,3)',
					'statute'    => 'DECIMAL(5,3)',
				),
				37  => array(
					'field_name' => 'left_right_balance',
					'scale'      => 1,
					'offset'     => 0,
					'units'      => '',
					'raw'        => 'SMALLINT UNSIGNED',
					'metric'     => 'SMALLINT UNSIGNED',
					'statute'    => 'SMALLINT UNSIGNED',
				),
				38  => array(
					'field_name' => 'end_position_lat',
					'scale'      => 1,
					'offset'     => 0,
					'units'      => 'semicircles',
					'raw'        => 'INT',
					'metric'     => 'DECIMAL(10,7)',
					'statute'    => 'DECIMAL(10,7)',
				),
				39  => array(
					'field_name' => 'end_position_long',
					'scale'      => 1,
					'offset'     => 0,
					'units'      => 'semicircles',
					'raw'        => 'INT',
					'metric'     => 'DECIMAL(11,7)',
					'statute'    => 'DECIMAL(11,7)',
				),
				41  => array(
					'field_name' => 'avg_stroke_count',
					'scale'      => 10,
					'offset'     => 0,
					'units'      => 'strokes/lap',
					'raw'        => 'DECIMAL(10,1)',
					'metric'     => 'DECIMAL(10,1)',
					'statute'    => 'DECIMAL(10,1)',
				),
				42  => array(
					'field_name' => 'avg_stroke_distance',
					'scale'      => 100,
					'offset'     => 0,
					'units'      => 'm',
					'raw'        => 'DECIMAL(10,2)',
					'metric'     => 'DECIMAL(10,5)',
					'statute'    => 'DECIMAL(10,5)',
				),
				43  => array(
					'field_name' => 'swim_stroke',
					'scale'      => 1,
					'offset'     => 0,
					'units'      => 'swim_stroke',
					'raw'        => 'TINYINT UNSIGNED',
					'metric'     => 'TINYINT UNSIGNED',
					'statute'    => 'TINYINT UNSIGNED',
				),
				44  => array(
					'field_name' => 'pool_length',
					'scale'      => 100,
					'offset'     => 0,
					'units'      => 'm',
					'raw'        => 'DECIMAL(5,2)',
					'metric'     => 'DECIMAL(5,2)',
					'statute'    => 'DECIMAL(5,2)',
				),
				45  => array(
					'field_name' => 'threshold_power',
					'scale'      => 1,
					'offset'     => 0,
					'units'      => 'watts',
					'raw'        => 'SMALLINT UNSIGNED',
					'metric'     => 'SMALLINT UNSIGNED',
					'statute'    => 'SMALLINT UNSIGNED',
				),
				46  => array(
					'field_name' => 'pool_length_unit',
					'scale'      => 1,
					'offset'     => 0,
					'units'      => '',
					'raw'        => 'TINYINT UNSIGNED',
					'metric'     => 'TINYINT UNSIGNED',
					'statute'    => 'TINYINT UNSIGNED',
				),
				47  => array(
					'field_name' => 'num_active_lengths',
					'scale'      => 1,
					'offset'     => 0,
					'units'      => 'lengths',
					'raw'        => 'SMALLINT UNSIGNED',
					'metric'     => 'SMALLINT UNSIGNED',
					'statute'    => 'SMALLINT UNSIGNED',
				),
				48  => array(
					'field_name' => 'total_work',
					'scale'      => 1,
					'offset'     => 0,
					'units'      => 'J',
					'raw'        => 'INT UNSIGNED',
					'metric'     => 'INT UNSIGNED',
					'statute'    => 'INT UNSIGNED',
				),
				65  => array(
					'field_name' => 'time_in_hr_zone',
					'scale'      => 1000,
					'offset'     => 0,
					'units'      => 's',
					'raw'        => 'DECIMAL(10,3)',
					'metric'     => 'DECIMAL(10,3)',
					'statute'    => 'DECIMAL(10,3)',
				),
				68  => array(
					'field_name' => 'time_in_power_zone',
					'scale'      => 1000,
					'offset'     => 0,
					'units'      => 's',
					'raw'        => 'DECIMAL(10,3)',
					'metric'     => 'DECIMAL(10,3)',
					'statute'    => 'DECIMAL(10,3)',
				),
				89  => array(
					'field_name' => 'avg_vertical_oscillation',
					'scale'      => 10,
					'offset'     => 0,
					'units'      => 'mm',
					'raw'        => 'DECIMAL(5,1)',
					'metric'     => 'DECIMAL(5,1)',
					'statute'    => 'DECIMAL(5,1)',
				),
				90  => array(
					'field_name' => 'avg_stance_time_percent',
					'scale'      => 100,
					'offset'     => 0,
					'units'      => 'percent',
					'raw'        => 'DECIMAL(5,2)',
					'metric'     => 'DECIMAL(5,2)',
					'statute'    => 'DECIMAL(5,2)',
				),
				91  => array(
					'field_name' => 'avg_stance_time',
					'scale'      => 10,
					'offset'     => 0,
					'units'      => 'ms',
					'raw'        => 'DECIMAL(5,1)',
					'metric'     => 'DECIMAL(5,1)',
					'statute'    => 'DECIMAL(5,1)',
				),
				92  => array(
					'field_name' => 'avg_fractional_cadence',
					'scale'      => 128,
					'offset'     => 0,
					'units'      => 'rpm',
					'raw'        => 'DECIMAL(8,7)',
					'metric'     => 'DECIMAL(8,7)',
					'statute'    => 'DECIMAL(8,7)',
				),
				93  => array(
					'field_name' => 'max_fractional_cadence',
					'scale'      => 128,
					'offset'     => 0,
					'units'      => 'rpm',
					'raw'        => 'DECIMAL(8,7)',
					'metric'     => 'DECIMAL(8,7)',
					'statute'    => 'DECIMAL(8,7)',
				),
				94  => array(
					'field_name' => 'total_fractional_cycles',
					'scale'      => 128,
					'offset'     => 0,
					'units'      => 'cycles',
					'raw'        => 'DECIMAL(8,7)',
					'metric'     => 'DECIMAL(8,7)',
					'statute'    => 'DECIMAL(8,7)',
				),
				101 => array(
					'field_name' => 'avg_left_torque_effectiveness',
					'scale'      => 2,
					'offset'     => 0,
					'units'      => 'percent',
					'raw'        => 'DECIMAL(4,1)',
					'metric'     => 'DECIMAL(4,1)',
					'statute'    => 'DECIMAL(4,1)',
				),
				102 => array(
					'field_name' => 'avg_right_torque_effectiveness',
					'scale'      => 2,
					'offset'     => 0,
					'units'      => 'percent',
					'raw'        => 'DECIMAL(4,1)',
					'metric'     => 'DECIMAL(4,1)',
					'statute'    => 'DECIMAL(4,1)',
				),
				103 => array(
					'field_name' => 'avg_left_pedal_smoothness',
					'scale'      => 2,
					'offset'     => 0,
					'units'      => 'percent',
					'raw'        => 'DECIMAL(4,1)',
					'metric'     => 'DECIMAL(4,1)',
					'statute'    => 'DECIMAL(4,1)',
				),
				104 => array(
					'field_name' => 'avg_right_pedal_smoothness',
					'scale'      => 2,
					'offset'     => 0,
					'units'      => 'percent',
					'raw'        => 'DECIMAL(4,1)',
					'metric'     => 'DECIMAL(4,1)',
					'statute'    => 'DECIMAL(4,1)',
				),
				105 => array(
					'field_name' => 'avg_combined_pedal_smoothness',
					'scale'      => 2,
					'offset'     => 0,
					'units'      => 'percent',
					'raw'        => 'DECIMAL(4,1)',
					'metric'     => 'DECIMAL(4,1)',
					'statute'    => 'DECIMAL(4,1)',
				),
				111 => array(
					'field_name' => 'sport_index',
					'scale'      => 1,
					'offset'     => 0,
					'units'      => '',
					'raw'        => 'TINYINT UNSIGNED',
					'metric'     => 'TINYINT UNSIGNED',
					'statute'    => 'TINYINT UNSIGNED',
				),
				112 => array(
					'field_name' => 'time_standing',
					'scale'      => 1000,
					'offset'     => 0,
					'units'      => 's',
					'raw'        => 'DECIMAL(10,3)',
					'metric'     => 'DECIMAL(10,3)',
					'statute'    => 'DECIMAL(10,3)',
				),
				113 => array(
					'field_name' => 'stand_count',
					'scale'      => 1,
					'offset'     => 0,
					'units'      => '',
					'raw'        => 'SMALLINT UNSIGNED',
					'metric'     => 'SMALLINT UNSIGNED',
					'statute'    => 'SMALLINT UNSIGNED',
				),
				114 => array(
					'field_name' => 'avg_left_pco',
					'scale'      => 1,
					'offset'     => 0,
					'units'      => 'mm',
					'raw'        => 'TINYINT',
					'metric'     => 'TINYINT',
					'statute'    => 'TINYINT',
				),
				115 => array(
					'field_name' => 'avg_right_pco',
					'scale'      => 1,
					'offset'     => 0,
					'units'      => 'mm',
					'raw'        => 'TINYINT',
					'metric'     => 'TINYINT',
					'statute'    => 'TINYINT',
				),
				116 => array(
					'field_name' => 'avg_left_power_phase',
					'scale'      => 0.7111111,
					'offset'     => 0,
					'units'      => 'degrees',
					'raw'        => 'VARCHAR(100)',
					'metric'     => 'VARCHAR(100)',
					'statute'    => 'VARCHAR(100)',
				),
				117 => array(
					'field_name' => 'avg_left_power_phase_peak',
					'scale'      => 0.7111111,
					'offset'     => 0,
					'units'      => 'degrees',
					'raw'        => 'VARCHAR(100)',
					'metric'     => 'VARCHAR(100)',
					'statute'    => 'VARCHAR(100)',
				),
				118 => array(
					'field_name' => 'avg_right_power_phase',
					'scale'      => 0.7111111,
					'offset'     => 0,
					'units'      => 'degrees',
					'raw'        => 'VARCHAR(100)',
					'metric'     => 'VARCHAR(100)',
					'statute'    => 'VARCHAR(100)',
				),
				119 => array(
					'field_name' => 'avg_right_power_phase_peak',
					'scale'      => 0.7111111,
					'offset'     => 0,
					'units'      => 'degrees',
					'raw'        => 'VARCHAR(100)',
					'metric'     => 'VARCHAR(100)',
					'statute'    => 'VARCHAR(100)',
				),
				120 => array(
					'field_name' => 'avg_power_position',
					'scale'      => 1,
					'offset'     => 0,
					'units'      => 'watts',
					'raw'        => 'VARCHAR(50)',
					'metric'     => 'VARCHAR(50)',
					'statute'    => 'VARCHAR(50)',
				),
				121 => array(
					'field_name' => 'max_power_position',
					'scale'      => 1,
					'offset'     => 0,
					'units'      => 'watts',
					'raw'        => 'VARCHAR(50)',
					'metric'     => 'VARCHAR(50)',
					'statute'    => 'VARCHAR(50)',
				),
				122 => array(
					'field_name' => 'avg_cadence_position',
					'scale'      => 1,
					'offset'     => 0,
					'units'      => 'rpm',
					'raw'        => 'VARCHAR(50)',
					'metric'     => 'VARCHAR(50)',
					'statute'    => 'VARCHAR(50)',
				),
				123 => array(
					'field_name' => 'max_cadence_position',
					'scale'      => 1,
					'offset'     => 0,
					'units'      => 'rpm',
					'raw'        => 'VARCHAR(50)',
					'metric'     => 'VARCHAR(50)',
					'statute'    => 'VARCHAR(50)',
				),
				253 => array(
					'field_name' => 'timestamp',
					'scale'      => 1,
					'offset'     => 0,
					'units'      => 's',
					'raw'        => 'INT UNSIGNED',
					'metric'     => 'INT UNSIGNED',
					'statute'    => 'INT UNSIGNED',
				),
				254 => array(
					'field_name' => 'message_index',
					'scale'      => 1,
					'offset'     => 0,
					'units'      => '',
					'raw'        => 'SMALLINT UNSIGNED',
					'metric'     => 'SMALLINT UNSIGNED',
					'statute'    => 'SMALLINT UNSIGNED',
				),
				192 => array(
					'field_name' => 'workout_feel',
					'scale'      => 10,
					'offset'     => 0,
					'units'      => '',
					'raw'        => 'TINYINT UNSIGNED',
					'metric'     => 'TINYINT UNSIGNED',
					'statute'    => 'TINYINT UNSIGNED',
				),
				193 => array(
					'field_name' => 'workout_rpe',
					'scale'      => 10,
					'offset'     => 0,
					'units'      => '',
					'raw'        => 'TINYINT UNSIGNED',
					'metric'     => 'TINYINT UNSIGNED',
					'statute'    => 'TINYINT UNSIGNED',
				),
			),
		),

		19  => array(
			'mesg_name'   => 'lap',
			'field_defns' => array(
				0   => array(
					'field_name' => 'event',
					'scale'      => 1,
					'offset'     => 0,
					'units'      => '',
					'raw'        => 'TINYINT UNSIGNED',
					'metric'     => 'TINYINT UNSIGNED',
					'statute'    => 'TINYINT UNSIGNED',
				),
				1   => array(
					'field_name' => 'event_type',
					'scale'      => 1,
					'offset'     => 0,
					'units'      => '',
					'raw'        => 'TINYINT UNSIGNED',
					'metric'     => 'TINYINT UNSIGNED',
					'statute'    => 'TINYINT UNSIGNED',
				),
				2   => array(
					'field_name' => 'start_time',
					'scale'      => 1,
					'offset'     => 0,
					'units'      => '',
					'raw'        => 'INT UNSIGNED',
					'metric'     => 'INT UNSIGNED',
					'statute'    => 'INT UNSIGNED',
				),
				3   => array(
					'field_name' => 'start_position_lat',
					'scale'      => 1,
					'offset'     => 0,
					'units'      => 'semicircles',
					'raw'        => 'INT',
					'metric'     => 'DECIMAL(10,7)',
					'statute'    => 'DECIMAL(10,7)',
				),
				4   => array(
					'field_name' => 'start_position_long',
					'scale'      => 1,
					'offset'     => 0,
					'units'      => 'semicircles',
					'raw'        => 'INT',
					'metric'     => 'DECIMAL(11,7)',
					'statute'    => 'DECIMAL(11,7)',
				),
				5   => array(
					'field_name' => 'end_position_lat',
					'scale'      => 1,
					'offset'     => 0,
					'units'      => 'semicircles',
					'raw'        => 'INT',
					'metric'     => 'DECIMAL(10,7)',
					'statute'    => 'DECIMAL(10,7)',
				),
				6   => array(
					'field_name' => 'end_position_long',
					'scale'      => 1,
					'offset'     => 0,
					'units'      => 'semicircles',
					'raw'        => 'INT',
					'metric'     => 'DECIMAL(11,7)',
					'statute'    => 'DECIMAL(11,7)',
				),
				7   => array(
					'field_name' => 'total_elapsed_time',
					'scale'      => 1000,
					'offset'     => 0,
					'units'      => 's',
					'raw'        => 'DECIMAL(10,3)',
					'metric'     => 'DECIMAL(10,3)',
					'statute'    => 'DECIMAL(10,3)',
				),
				8   => array(
					'field_name' => 'total_timer_time',
					'scale'      => 1000,
					'offset'     => 0,
					'units'      => 's',
					'raw'        => 'DECIMAL(10,3)',
					'metric'     => 'DECIMAL(10,3)',
					'statute'    => 'DECIMAL(10,3)',
				),
				9   => array(
					'field_name' => 'total_distance',
					'scale'      => 100,
					'offset'     => 0,
					'units'      => 'm',
					'raw'        => 'DECIMAL(10,2)',
					'metric'     => 'DECIMAL(10,5)',
					'statute'    => 'DECIMAL(10,5)',
				),
				10  => array(
					'field_name' => 'total_cycles',
					'scale'      => 1,
					'offset'     => 0,
					'units'      => 'cycles',
					'raw'        => 'INT UNSIGNED',
					'metric'     => 'INT UNSIGNED',
					'statute'    => 'INT UNSIGNED',
				),
				11  => array(
					'field_name' => 'total_calories',
					'scale'      => 1,
					'offset'     => 0,
					'units'      => 'kcal',
					'raw'        => 'SMALLINT UNSIGNED',
					'metric'     => 'SMALLINT UNSIGNED',
					'statute'    => 'SMALLINT UNSIGNED',
				),
				12  => array(
					'field_name' => 'total_fat_calories',
					'scale'      => 1,
					'offset'     => 0,
					'units'      => 'kcal',
					'raw'        => 'SMALLINT UNSIGNED',
					'metric'     => 'SMALLINT UNSIGNED',
					'statute'    => 'SMALLINT UNSIGNED',
				),
				13  => array(
					'field_name' => 'avg_speed',
					'scale'      => 1000,
					'offset'     => 0,
					'units'      => 'm/s',
					'raw'        => 'DECIMAL(5,3)',
					'metric'     => 'DECIMAL(7,4)',
					'statute'    => 'DECIMAL(7,4)',
				),
				14  => array(
					'field_name' => 'max_speed',
					'scale'      => 1000,
					'offset'     => 0,
					'units'      => 'm/s',
					'raw'        => 'DECIMAL(5,3)',
					'metric'     => 'DECIMAL(7,4)',
					'statute'    => 'DECIMAL(7,4)',
				),
				15  => array(
					'field_name' => 'avg_heart_rate',
					'scale'      => 1,
					'offset'     => 0,
					'units'      => 'bpm',
					'raw'        => 'TINYINT UNSIGNED',
					'metric'     => 'TINYINT UNSIGNED',
					'statute'    => 'TINYINT UNSIGNED',
				),
				16  => array(
					'field_name' => 'max_heart_rate',
					'scale'      => 1,
					'offset'     => 0,
					'units'      => 'bpm',
					'raw'        => 'TINYINT UNSIGNED',
					'metric'     => 'TINYINT UNSIGNED',
					'statute'    => 'TINYINT UNSIGNED',
				),
				17  => array(
					'field_name' => 'avg_cadence',
					'scale'      => 1,
					'offset'     => 0,
					'units'      => 'rpm',
					'raw'        => 'TINYINT UNSIGNED',
					'metric'     => 'TINYINT UNSIGNED',
					'statute'    => 'TINYINT UNSIGNED',
				),
				18  => array(
					'field_name' => 'max_cadence',
					'scale'      => 1,
					'offset'     => 0,
					'units'      => 'rpm',
					'raw'        => 'TINYINT UNSIGNED',
					'metric'     => 'TINYINT UNSIGNED',
					'statute'    => 'TINYINT UNSIGNED',
				),
				19  => array(
					'field_name' => 'avg_power',
					'scale'      => 1,
					'offset'     => 0,
					'units'      => 'watts',
					'raw'        => 'SMALLINT UNSIGNED',
					'metric'     => 'SMALLINT UNSIGNED',
					'statute'    => 'SMALLINT UNSIGNED',
				),
				20  => array(
					'field_name' => 'max_power',
					'scale'      => 1,
					'offset'     => 0,
					'units'      => 'watts',
					'raw'        => 'SMALLINT UNSIGNED',
					'metric'     => 'SMALLINT UNSIGNED',
					'statute'    => 'SMALLINT UNSIGNED',
				),
				21  => array(
					'field_name' => 'total_ascent',
					'scale'      => 1,
					'offset'     => 0,
					'units'      => 'm',
					'raw'        => 'SMALLINT UNSIGNED',
					'metric'     => 'SMALLINT UNSIGNED',
					'statute'    => 'DECIMAL(7,1)',
				),
				22  => array(
					'field_name' => 'total_descent',
					'scale'      => 1,
					'offset'     => 0,
					'units'      => 'm',
					'raw'        => 'SMALLINT UNSIGNED',
					'metric'     => 'SMALLINT UNSIGNED',
					'statute'    => 'DECIMAL(7,1)',
				),
				23  => array(
					'field_name' => 'intensity',
					'scale'      => 1,
					'offset'     => 0,
					'units'      => '',
					'raw'        => 'TINYINT UNSIGNED',
					'metric'     => 'TINYINT UNSIGNED',
					'statute'    => 'TINYINT UNSIGNED',
				),
				24  => array(
					'field_name' => 'lap_trigger',
					'scale'      => 1,
					'offset'     => 0,
					'units'      => '',
					'raw'        => 'TINYINT UNSIGNED',
					'metric'     => 'TINYINT UNSIGNED',
					'statute'    => 'TINYINT UNSIGNED',
				),
				25  => array(
					'field_name' => 'sport',
					'scale'      => 1,
					'offset'     => 0,
					'units'      => '',
					'raw'        => 'TINYINT UNSIGNED',
					'metric'     => 'TINYINT UNSIGNED',
					'statute'    => 'TINYINT UNSIGNED',
				),
				26  => array(
					'field_name' => 'event_group',
					'scale'      => 1,
					'offset'     => 0,
					'units'      => '',
					'raw'        => 'TINYINT UNSIGNED',
					'metric'     => 'TINYINT UNSIGNED',
					'statute'    => 'TINYINT UNSIGNED',
				),
				32  => array(
					'field_name' => 'num_lengths',
					'scale'      => 1,
					'offset'     => 0,
					'units'      => 'lengths',
					'raw'        => 'SMALLINT UNSIGNED',
					'metric'     => 'SMALLINT UNSIGNED',
					'statute'    => 'SMALLINT UNSIGNED',
				),
				33  => array(
					'field_name' => 'normalized_power',
					'scale'      => 1,
					'offset'     => 0,
					'units'      => 'watts',
					'raw'        => 'SMALLINT UNSIGNED',
					'metric'     => 'SMALLINT UNSIGNED',
					'statute'    => 'SMALLINT UNSIGNED',
				),
				34  => array(
					'field_name' => 'left_right_balance',
					'scale'      => 1,
					'offset'     => 0,
					'units'      => '',
					'raw'        => 'SMALLINT UNSIGNED',
					'metric'     => 'SMALLINT UNSIGNED',
					'statute'    => 'SMALLINT UNSIGNED',
				),
				35  => array(
					'field_name' => 'first_length_index',
					'scale'      => 1,
					'offset'     => 0,
					'units'      => '',
					'raw'        => 'SMALLINT UNSIGNED',
					'metric'     => 'SMALLINT UNSIGNED',
					'statute'    => 'SMALLINT UNSIGNED',
				),
				37  => array(
					'field_name' => 'avg_stroke_distance',
					'scale'      => 100,
					'offset'     => 0,
					'units'      => 'm',
					'raw'        => 'DECIMAL(10,2)',
					'metric'     => 'DECIMAL(10,5)',
					'statute'    => 'DECIMAL(10,5)',
				),
				38  => array(
					'field_name' => 'swim_stroke',
					'scale'      => 1,
					'offset'     => 0,
					'units'      => '',
					'raw'        => 'TINYINT UNSIGNED',
					'metric'     => 'TINYINT UNSIGNED',
					'statute'    => 'TINYINT UNSIGNED',
				),
				39  => array(
					'field_name' => 'sub_sport',
					'scale'      => 1,
					'offset'     => 0,
					'units'      => '',
					'raw'        => 'TINYINT UNSIGNED',
					'metric'     => 'TINYINT UNSIGNED',
					'statute'    => 'TINYINT UNSIGNED',
				),
				40  => array(
					'field_name' => 'num_active_lengths',
					'scale'      => 1,
					'offset'     => 0,
					'units'      => 'lengths',
					'raw'        => 'SMALLINT UNSIGNED',
					'metric'     => 'SMALLINT UNSIGNED',
					'statute'    => 'SMALLINT UNSIGNED',
				),
				41  => array(
					'field_name' => 'total_work',
					'scale'      => 1,
					'offset'     => 0,
					'units'      => 'J',
					'raw'        => 'INT UNSIGNED',
					'metric'     => 'INT UNSIGNED',
					'statute'    => 'INT UNSIGNED',
				),
				57  => array(
					'field_name' => 'time_in_hr_zone',
					'scale'      => 1000,
					'offset'     => 0,
					'units'      => 's',
					'raw'        => 'DECIMAL(10,3)',
					'metric'     => 'DECIMAL(10,3)',
					'statute'    => 'DECIMAL(10,3)',
				),
				60  => array(
					'field_name' => 'time_in_power_zone',
					'scale'      => 1000,
					'offset'     => 0,
					'units'      => 's',
					'raw'        => 'DECIMAL(10,3)',
					'metric'     => 'DECIMAL(10,3)',
					'statute'    => 'DECIMAL(10,3)',
				),
				71  => array(
					'field_name' => 'wkt_step_index',
					'scale'      => 1,
					'offset'     => 0,
					'units'      => '',
					'raw'        => 'SMALLINT UNSIGNED',
					'metric'     => 'SMALLINT UNSIGNED',
					'statute'    => 'SMALLINT UNSIGNED',
				),
				77  => array(
					'field_name' => 'avg_vertical_oscillation',
					'scale'      => 10,
					'offset'     => 0,
					'units'      => 'mm',
					'raw'        => 'DECIMAL(5,1)',
					'metric'     => 'DECIMAL(5,1)',
					'statute'    => 'DECIMAL(5,1)',
				),
				78  => array(
					'field_name' => 'avg_stance_time_percent',
					'scale'      => 100,
					'offset'     => 0,
					'units'      => 'percent',
					'raw'        => 'DECIMAL(5,2)',
					'metric'     => 'DECIMAL(5,2)',
					'statute'    => 'DECIMAL(5,2)',
				),
				79  => array(
					'field_name' => 'avg_stance_time',
					'scale'      => 10,
					'offset'     => 0,
					'units'      => 'ms',
					'raw'        => 'DECIMAL(5,1)',
					'metric'     => 'DECIMAL(5,1)',
					'statute'    => 'DECIMAL(5,1)',
				),
				80  => array(
					'field_name' => 'avg_fractional_cadence',
					'scale'      => 128,
					'offset'     => 0,
					'units'      => 'rpm',
					'raw'        => 'DECIMAL(8,7)',
					'metric'     => 'DECIMAL(8,7)',
					'statute'    => 'DECIMAL(8,7)',
				),
				81  => array(
					'field_name' => 'max_fractional_cadence',
					'scale'      => 128,
					'offset'     => 0,
					'units'      => 'rpm',
					'raw'        => 'DECIMAL(8,7)',
					'metric'     => 'DECIMAL(8,7)',
					'statute'    => 'DECIMAL(8,7)',
				),
				82  => array(
					'field_name' => 'total_fractional_cycles',
					'scale'      => 128,
					'offset'     => 0,
					'units'      => 'cycles',
					'raw'        => 'DECIMAL(8,7)',
					'metric'     => 'DECIMAL(8,7)',
					'statute'    => 'DECIMAL(8,7)',
				),
				91  => array(
					'field_name' => 'avg_left_torque_effectiveness',
					'scale'      => 2,
					'offset'     => 0,
					'units'      => 'percent',
					'raw'        => 'DECIMAL(4,1)',
					'metric'     => 'DECIMAL(4,1)',
					'statute'    => 'DECIMAL(4,1)',
				),
				92  => array(
					'field_name' => 'avg_right_torque_effectiveness',
					'scale'      => 2,
					'offset'     => 0,
					'units'      => 'percent',
					'raw'        => 'DECIMAL(4,1)',
					'metric'     => 'DECIMAL(4,1)',
					'statute'    => 'DECIMAL(4,1)',
				),
				93  => array(
					'field_name' => 'avg_left_pedal_smoothness',
					'scale'      => 2,
					'offset'     => 0,
					'units'      => 'percent',
					'raw'        => 'DECIMAL(4,1)',
					'metric'     => 'DECIMAL(4,1)',
					'statute'    => 'DECIMAL(4,1)',
				),
				94  => array(
					'field_name' => 'avg_right_pedal_smoothness',
					'scale'      => 2,
					'offset'     => 0,
					'units'      => 'percent',
					'raw'        => 'DECIMAL(4,1)',
					'metric'     => 'DECIMAL(4,1)',
					'statute'    => 'DECIMAL(4,1)',
				),
				95  => array(
					'field_name' => 'avg_combined_pedal_smoothness',
					'scale'      => 2,
					'offset'     => 0,
					'units'      => 'percent',
					'raw'        => 'DECIMAL(4,1)',
					'metric'     => 'DECIMAL(4,1)',
					'statute'    => 'DECIMAL(4,1)',
				),
				98  => array(
					'field_name' => 'time_standing',
					'scale'      => 1000,
					'offset'     => 0,
					'units'      => 's',
					'raw'        => 'DECIMAL(10,3)',
					'metric'     => 'DECIMAL(10,3)',
					'statute'    => 'DECIMAL(10,3)',
				),
				99  => array(
					'field_name' => 'stand_count',
					'scale'      => 1,
					'offset'     => 0,
					'units'      => '',
					'raw'        => 'SMALLINT UNSIGNED',
					'metric'     => 'SMALLINT UNSIGNED',
					'statute'    => 'SMALLINT UNSIGNED',
				),
				100 => array(
					'field_name' => 'avg_left_pco',
					'scale'      => 1,
					'offset'     => 0,
					'units'      => 'mm',
					'raw'        => 'TINYINT',
					'metric'     => 'TINYINT',
					'statute'    => 'TINYINT',
				),
				101 => array(
					'field_name' => 'avg_right_pco',
					'scale'      => 1,
					'offset'     => 0,
					'units'      => 'mm',
					'raw'        => 'TINYINT',
					'metric'     => 'TINYINT',
					'statute'    => 'TINYINT',
				),
				102 => array(
					'field_name' => 'avg_left_power_phase',
					'scale'      => 0.7111111,
					'offset'     => 0,
					'units'      => 'degrees',
					'raw'        => 'VARCHAR(100)',
					'metric'     => 'VARCHAR(100)',
					'statute'    => 'VARCHAR(100)',
				),
				103 => array(
					'field_name' => 'avg_left_power_phase_peak',
					'scale'      => 0.7111111,
					'offset'     => 0,
					'units'      => 'degrees',
					'raw'        => 'VARCHAR(100)',
					'metric'     => 'VARCHAR(100)',
					'statute'    => 'VARCHAR(100)',
				),
				104 => array(
					'field_name' => 'avg_right_power_phase',
					'scale'      => 0.7111111,
					'offset'     => 0,
					'units'      => 'degrees',
					'raw'        => 'VARCHAR(100)',
					'metric'     => 'VARCHAR(100)',
					'statute'    => 'VARCHAR(100)',
				),
				105 => array(
					'field_name' => 'avg_right_power_phase_peak',
					'scale'      => 0.7111111,
					'offset'     => 0,
					'units'      => 'degrees',
					'raw'        => 'VARCHAR(100)',
					'metric'     => 'VARCHAR(100)',
					'statute'    => 'VARCHAR(100)',
				),
				106 => array(
					'field_name' => 'avg_power_position',
					'scale'      => 1,
					'offset'     => 0,
					'units'      => 'watts',
					'raw'        => 'VARCHAR(50)',
					'metric'     => 'VARCHAR(50)',
					'statute'    => 'VARCHAR(50)',
				),
				107 => array(
					'field_name' => 'max_power_position',
					'scale'      => 1,
					'offset'     => 0,
					'units'      => 'watts',
					'raw'        => 'VARCHAR(50)',
					'metric'     => 'VARCHAR(50)',
					'statute'    => 'VARCHAR(50)',
				),
				108 => array(
					'field_name' => 'avg_cadence_position',
					'scale'      => 1,
					'offset'     => 0,
					'units'      => 'rpm',
					'raw'        => 'VARCHAR(50)',
					'metric'     => 'VARCHAR(50)',
					'statute'    => 'VARCHAR(50)',
				),
				109 => array(
					'field_name' => 'max_cadence_position',
					'scale'      => 1,
					'offset'     => 0,
					'units'      => 'rpm',
					'raw'        => 'VARCHAR(50)',
					'metric'     => 'VARCHAR(50)',
					'statute'    => 'VARCHAR(50)',
				),
				253 => array(
					'field_name' => 'timestamp',
					'scale'      => 1,
					'offset'     => 0,
					'units'      => 's',
					'raw'        => 'INT UNSIGNED',
					'metric'     => 'INT UNSIGNED',
					'statute'    => 'INT UNSIGNED',
				),
				254 => array(
					'field_name' => 'message_index',
					'scale'      => 1,
					'offset'     => 0,
					'units'      => '',
					'raw'        => 'SMALLINT UNSIGNED',
					'metric'     => 'SMALLINT UNSIGNED',
					'statute'    => 'SMALLINT UNSIGNED',
				),
			),
		),

		20  => array(
			'mesg_name'   => 'record',
			'field_defns' => array(
				0   => array(
					'field_name' => 'position_lat',
					'scale'      => 1,
					'offset'     => 0,
					'units'      => 'semicircles',
					'raw'        => 'INT',
					'metric'     => 'DECIMAL(10,7)',
					'statute'    => 'DECIMAL(10,7)',
				),
				1   => array(
					'field_name' => 'position_long',
					'scale'      => 1,
					'offset'     => 0,
					'units'      => 'semicircles',
					'raw'        => 'INT',
					'metric'     => 'DECIMAL(11,7)',
					'statute'    => 'DECIMAL(11,7)',
				),
				2   => array(
					'field_name' => 'altitude',
					'scale'      => 5,
					'offset'     => 500,
					'units'      => 'm',
					'raw'        => 'DECIMAL(6,1)',
					'metric'     => 'DECIMAL(6,1)',
					'statute'    => 'DECIMAL(7,2)',

				),
				3   => array(
					'field_name' => 'heart_rate',
					'scale'      => 1,
					'offset'     => 0,
					'units'      => 'bpm',
					'raw'        => 'TINYINT UNSIGNED',
					'metric'     => 'TINYINT UNSIGNED',
					'statute'    => 'TINYINT UNSIGNED',
				),
				4   => array(
					'field_name' => 'cadence',
					'scale'      => 1,
					'offset'     => 0,
					'units'      => 'rpm',
					'raw'        => 'TINYINT UNSIGNED',
					'metric'     => 'TINYINT UNSIGNED',
					'statute'    => 'TINYINT UNSIGNED',
				),
				5   => array(
					'field_name' => 'distance',
					'scale'      => 100,
					'offset'     => 0,
					'units'      => 'm',
					'raw'        => 'DECIMAL(10,2)',
					'metric'     => 'DECIMAL(10,5)',
					'statute'    => 'DECIMAL(10,5)',
				),
				6   => array(
					'field_name' => 'speed',
					'scale'      => 1000,
					'offset'     => 0,
					'units'      => 'm/s',
					'raw'        => 'DECIMAL(5,3)',
					'metric'     => 'DECIMAL(7,4)',
					'statute'    => 'DECIMAL(7,4)',
				),
				7   => array(
					'field_name' => 'power',
					'scale'      => 1,
					'offset'     => 0,
					'units'      => 'watts',
					'raw'        => 'SMALLINT UNSIGNED',
					'metric'     => 'SMALLINT UNSIGNED',
					'statute'    => 'SMALLINT UNSIGNED',
				),
				8   => array(
					'field_name' => 'compressed_speed_distance',
					'scale'      => 100,
					'offset'     => 0,
					'units'      => 'm/s,m',
				),
				9   => array(
					'field_name' => 'grade',
					'scale'      => 100,
					'offset'     => 0,
					'units'      => 'percent',
					'raw'        => 'DECIMAL(5,2)',
					'metric'     => 'DECIMAL(5,2)',
					'statute'    => 'DECIMAL(5,2)',
				),
				10  => array(
					'field_name' => 'resistance',
					'scale'      => 1,
					'offset'     => 0,
					'units'      => '',
					'raw'        => 'TINYINT UNSIGNED',
					'metric'     => 'TINYINT UNSIGNED',
					'statute'    => 'TINYINT UNSIGNED',
				),
				11  => array(
					'field_name' => 'time_from_course',
					'scale'      => 1000,
					'offset'     => 0,
					'units'      => 's',
					'raw'        => 'DECIMAL(10,3)',
					'metric'     => 'DECIMAL(10,3)',
					'statute'    => 'DECIMAL(10,3)',
				),
				12  => array(
					'field_name' => 'cycle_length',
					'scale'      => 100,
					'offset'     => 0,
					'units'      => 'm',
					'raw'        => 'DECIMAL(3,2)',
					'metric'     => 'DECIMAL(3,2)',
					'statute'    => 'DECIMAL(3,2)',
				),
				13  => array(
					'field_name' => 'temperature',
					'scale'      => 1,
					'offset'     => 0,
					'units'      => 'C',
					'raw'        => 'TINYINT',
					'metric'     => 'TINYINT',
					'statute'    => 'DECIMAL(4,1)',
				),
				17  => array(
					'field_name' => 'speed_1s',
					'scale'      => 16,
					'offset'     => 0,
					'units'      => 'm/s',
					'raw'        => 'DECIMAL(6,4)',
					'metric'     => 'DECIMAL(8,5)',
					'statute'    => 'DECIMAL(8,5)',
				),
				18  => array(
					'field_name' => 'cycles',
					'scale'      => 1,
					'offset'     => 0,
					'units'      => 'cycles',
					'raw'        => 'TINYINT UNSIGNED',
					'metric'     => 'TINYINT UNSIGNED',
					'statute'    => 'TINYINT UNSIGNED',
				),
				19  => array(
					'field_name' => 'total_cycles',
					'scale'      => 1,
					'offset'     => 0,
					'units'      => 'cycles',
					'raw'        => 'INT UNSIGNED',
					'metric'     => 'INT UNSIGNED',
					'statute'    => 'INT UNSIGNED',
				),
				28  => array(
					'field_name' => 'compressed_accumulated_power',
					'scale'      => 1,
					'offset'     => 0,
					'units'      => 'watts',
					'raw'        => 'SMALLINT UNSIGNED',
					'metric'     => 'SMALLINT UNSIGNED',
					'statute'    => 'SMALLINT UNSIGNED',
				),
				29  => array(
					'field_name' => 'accumulated_power',
					'scale'      => 1,
					'offset'     => 0,
					'units'      => 'watts',
					'raw'        => 'INT UNSIGNED',
					'metric'     => 'INT UNSIGNED',
					'statute'    => 'INT UNSIGNED',
				),
				30  => array(
					'field_name' => 'left_right_balance',
					'scale'      => 1,
					'offset'     => 0,
					'units'      => '',
					'raw'        => 'TINYINT UNSIGNED',
					'metric'     => 'TINYINT UNSIGNED',
					'statute'    => 'TINYINT UNSIGNED',
				),
				31  => array(
					'field_name' => 'gps_accuracy',
					'scale'      => 1,
					'offset'     => 0,
					'units'      => 'm',
					'raw'        => 'TINYINT UNSIGNED',
					'metric'     => 'TINYINT UNSIGNED',
					'statute'    => 'TINYINT UNSIGNED',
				),
				32  => array(
					'field_name' => 'vertical_speed',
					'scale'      => 1000,
					'offset'     => 0,
					'units'      => 'm/s',
					'raw'        => 'DECIMAL(5,3)',
					'metric'     => 'DECIMAL(7,4)',
					'statute'    => 'DECIMAL(7,4)',
				),
				33  => array(
					'field_name' => 'calories',
					'scale'      => 1,
					'offset'     => 0,
					'units'      => 'kcal',
					'raw'        => 'SMALLINT UNSIGNED',
					'metric'     => 'SMALLINT UNSIGNED',
					'statute'    => 'SMALLINT UNSIGNED',
				),
				39  => array(
					'field_name' => 'vertical_oscillation',
					'scale'      => 10,
					'offset'     => 0,
					'units'      => 'mm',
					'raw'        => 'DECIMAL(5,1)',
					'metric'     => 'DECIMAL(5,1)',
					'statute'    => 'DECIMAL(5,1)',
				),
				40  => array(
					'field_name' => 'stance_time_percent',
					'scale'      => 100,
					'offset'     => 0,
					'units'      => 'percent',
					'raw'        => 'DECIMAL(5,2)',
					'metric'     => 'DECIMAL(5,2)',
					'statute'    => 'DECIMAL(5,2)',
				),
				41  => array(
					'field_name' => 'stance_time',
					'scale'      => 10,
					'offset'     => 0,
					'units'      => 'ms',
					'raw'        => 'DECIMAL(5,1)',
					'metric'     => 'DECIMAL(5,1)',
					'statute'    => 'DECIMAL(5,1)',
				),
				42  => array(
					'field_name' => 'activity_type',
					'scale'      => 1,
					'offset'     => 0,
					'units'      => '',
					'raw'        => 'TINYINT UNSIGNED',
					'metric'     => 'TINYINT UNSIGNED',
					'statute'    => 'TINYINT UNSIGNED',
				),
				43  => array(
					'field_name' => 'left_torque_effectiveness',
					'scale'      => 2,
					'offset'     => 0,
					'units'      => 'percent',
					'raw'        => 'DECIMAL(4,1)',
					'metric'     => 'DECIMAL(4,1)',
					'statute'    => 'DECIMAL(4,1)',
				),
				44  => array(
					'field_name' => 'right_torque_effectiveness',
					'scale'      => 2,
					'offset'     => 0,
					'units'      => 'percent',
					'raw'        => 'DECIMAL(4,1)',
					'metric'     => 'DECIMAL(4,1)',
					'statute'    => 'DECIMAL(4,1)',
				),
				45  => array(
					'field_name' => 'left_pedal_smoothness',
					'scale'      => 2,
					'offset'     => 0,
					'units'      => 'percent',
					'raw'        => 'DECIMAL(4,1)',
					'metric'     => 'DECIMAL(4,1)',
					'statute'    => 'DECIMAL(4,1)',
				),
				46  => array(
					'field_name' => 'right_pedal_smoothness',
					'scale'      => 2,
					'offset'     => 0,
					'units'      => 'percent',
					'raw'        => 'DECIMAL(4,1)',
					'metric'     => 'DECIMAL(4,1)',
					'statute'    => 'DECIMAL(4,1)',
				),
				47  => array(
					'field_name' => 'combined_pedal_smoothness',
					'scale'      => 2,
					'offset'     => 0,
					'units'      => 'percent',
					'raw'        => 'DECIMAL(4,1)',
					'metric'     => 'DECIMAL(4,1)',
					'statute'    => 'DECIMAL(4,1)',
				),
				48  => array(
					'field_name' => 'time128',
					'scale'      => 128,
					'offset'     => 0,
					'units'      => 's',
					'raw'        => 'DECIMAL(8,7)',
					'metric'     => 'DECIMAL(8,7)',
					'statute'    => 'DECIMAL(8,7)',
				),
				49  => array(
					'field_name' => 'stroke_type',
					'scale'      => 1,
					'offset'     => 0,
					'units'      => '',
					'raw'        => 'TINYINT UNSIGNED',
					'metric'     => 'TINYINT UNSIGNED',
					'statute'    => 'TINYINT UNSIGNED',
				),
				50  => array(
					'field_name' => 'zone',
					'scale'      => 1,
					'offset'     => 0,
					'units'      => '',
					'raw'        => 'TINYINT UNSIGNED',
					'metric'     => 'TINYINT UNSIGNED',
					'statute'    => 'TINYINT UNSIGNED',
				),
				51  => array(
					'field_name' => 'ball_speed',
					'scale'      => 100,
					'offset'     => 0,
					'units'      => 'm/s',
					'raw'        => 'DECIMAL(5,2)',
					'metric'     => 'DECIMAL(7,3)',
					'statute'    => 'DECIMAL(7,3)',
				),
				52  => array(
					'field_name' => 'cadence256',
					'scale'      => 256,
					'offset'     => 0,
					'units'      => 'rpm',
					'raw'        => 'DECIMAL(11,8)',
					'metric'     => 'DECIMAL(11,8)',
					'statute'    => 'DECIMAL(11,8)',
				),
				53  => array(
					'field_name' => 'fractional_cadence',
					'scale'      => 128,
					'offset'     => 0,
					'units'      => 'rpm',
					'raw'        => 'DECIMAL(8,7)',
					'metric'     => 'DECIMAL(8,7)',
					'statute'    => 'DECIMAL(8,7)',
				),
				54  => array(
					'field_name' => 'total_hemoglobin_conc',
					'scale'      => 100,
					'offset'     => 0,
					'units'      => 'g/dL',
					'raw'        => 'DECIMAL(5,2)',
					'metric'     => 'DECIMAL(5,2)',
					'statute'    => 'DECIMAL(5,2)',
				),
				55  => array(
					'field_name' => 'total_hemoglobin_conc_min',
					'scale'      => 100,
					'offset'     => 0,
					'units'      => 'g/dL',
					'raw'        => 'DECIMAL(5,2)',
					'metric'     => 'DECIMAL(5,2)',
					'statute'    => 'DECIMAL(5,2)',
				),
				56  => array(
					'field_name' => 'total_hemoglobin_conc_max',
					'scale'      => 100,
					'offset'     => 0,
					'units'      => 'g/dL',
					'raw'        => 'DECIMAL(5,2)',
					'metric'     => 'DECIMAL(5,2)',
					'statute'    => 'DECIMAL(5,2)',
				),
				57  => array(
					'field_name' => 'saturated_hemoglobin_percent',
					'scale'      => 10,
					'offset'     => 0,
					'units'      => '%',
					'raw'        => 'DECIMAL(5,1)',
					'metric'     => 'DECIMAL(5,1)',
					'statute'    => 'DECIMAL(5,1)',
				),
				58  => array(
					'field_name' => 'saturated_hemoglobin_percent_min',
					'scale'      => 10,
					'offset'     => 0,
					'units'      => '%',
					'raw'        => 'DECIMAL(5,1)',
					'metric'     => 'DECIMAL(5,1)',
					'statute'    => 'DECIMAL(5,1)',
				),
				59  => array(
					'field_name' => 'saturated_hemoglobin_percent_max',
					'scale'      => 10,
					'offset'     => 0,
					'units'      => '%',
					'raw'        => 'DECIMAL(5,1)',
					'metric'     => 'DECIMAL(5,1)',
					'statute'    => 'DECIMAL(5,1)',
				),
				62  => array(
					'field_name' => 'device_index',
					'scale'      => 1,
					'offset'     => 0,
					'units'      => '',
					'raw'        => 'TINYINT UNSIGNED',
					'metric'     => 'TINYINT UNSIGNED',
					'statute'    => 'TINYINT UNSIGNED',
				),
				67  => array(
					'field_name' => 'left_pco',
					'scale'      => 1,
					'offset'     => 0,
					'units'      => 'mm',
					'raw'        => 'TINYINT',
					'metric'     => 'TINYINT',
					'statute'    => 'TINYINT',
				),
				68  => array(
					'field_name' => 'right_pco',
					'scale'      => 1,
					'offset'     => 0,
					'units'      => 'mm',
					'raw'        => 'TINYINT',
					'metric'     => 'TINYINT',
					'statute'    => 'TINYINT',
				),
				69  => array(
					'field_name' => 'left_power_phase',
					'scale'      => 0.7111111,
					'offset'     => 0,
					'units'      => 'degrees',
					'raw'        => 'FLOAT',
					'metric'     => 'FLOAT',
					'statute'    => 'FLOAT',
				),
				70  => array(
					'field_name' => 'left_power_phase_peak',
					'scale'      => 0.7111111,
					'offset'     => 0,
					'units'      => 'degrees',
					'raw'        => 'FLOAT',
					'metric'     => 'FLOAT',
					'statute'    => 'FLOAT',
				),
				71  => array(
					'field_name' => 'right_power_phase',
					'scale'      => 0.7111111,
					'offset'     => 0,
					'units'      => 'degrees',
					'raw'        => 'FLOAT',
					'metric'     => 'FLOAT',
					'statute'    => 'FLOAT',
				),
				72  => array(
					'field_name' => 'right_power_phase_peak',
					'scale'      => 0.7111111,
					'offset'     => 0,
					'units'      => 'degrees',
					'raw'        => 'FLOAT',
					'metric'     => 'FLOAT',
					'statute'    => 'FLOAT',
				),
				73  => array(
					'field_name' => 'enhanced_speed',
					'scale'      => 1000,
					'offset'     => 0,
					'units'      => 'm/s',
					'raw'        => 'DECIMAL(10,3)',
					'metric'     => 'DECIMAL(12,4)',
					'statute'    => 'DECIMAL(12,4)',
				),
				78  => array(
					'field_name' => 'enhanced_altitude',
					'scale'      => 5,
					'offset'     => 500,
					'units'      => 'm',
					'raw'        => 'DECIMAL(10,1)',
					'metric'     => 'DECIMAL(10,4)',
					'statute'    => 'DECIMAL(10,4)',
				),
				81  => array(
					'field_name' => 'battery_soc',
					'scale'      => 2,
					'offset'     => 0,
					'units'      => 'percent',
					'raw'        => 'DECIMAL(4,1)',
					'metric'     => 'DECIMAL(4,1)',
					'statute'    => 'DECIMAL(4,1)',
				),
				82  => array(
					'field_name' => 'motor_power',
					'scale'      => 1,
					'offset'     => 0,
					'units'      => 'watts',
					'raw'        => 'SMALLINT UNSIGNED',
					'metric'     => 'SMALLINT UNSIGNED',
					'statute'    => 'SMALLINT UNSIGNED',
				),
				83  => array(
					'field_name' => 'vertical_ratio',
					'scale'      => 100,
					'offset'     => 0,
					'units'      => 'percent',
					'raw'        => 'DECIMAL(5,2)',
					'metric'     => 'DECIMAL(5,2)',
					'statute'    => 'DECIMAL(5,2)',
				),
				84  => array(
					'field_name' => 'stance_time_balance',
					'scale'      => 100,
					'offset'     => 0,
					'units'      => 'percent',
					'raw'        => 'DECIMAL(5,2)',
					'metric'     => 'DECIMAL(5,2)',
					'statute'    => 'DECIMAL(5,2)',
				),
				85  => array(
					'field_name' => 'step_length',
					'scale'      => 10,
					'offset'     => 0,
					'units'      => 'mm',
					'raw'        => 'DECIMAL(5,2)',
					'metric'     => 'DECIMAL(5,2)',
					'statute'    => 'DECIMAL(5,2)',
				),
				253 => array(
					'field_name' => 'timestamp',
					'scale'      => 1,
					'offset'     => 0,
					'units'      => 's',
					'raw'        => 'INT UNSIGNED',
					'metric'     => 'INT UNSIGNED',
					'statute'    => 'INT UNSIGNED',
				),
			),
		),

		21  => array(
			'mesg_name'   => 'event',
			'field_defns' => array(
				0   => array(
					'field_name' => 'event',
					'scale'      => 1,
					'offset'     => 0,
					'units'      => '',
					'raw'        => 'TINYINT UNSIGNED',
					'metric'     => 'TINYINT UNSIGNED',
					'statute'    => 'TINYINT UNSIGNED',
				),
				1   => array(
					'field_name' => 'event_type',
					'scale'      => 1,
					'offset'     => 0,
					'units'      => '',
					'raw'        => 'TINYINT UNSIGNED',
					'metric'     => 'TINYINT UNSIGNED',
					'statute'    => 'TINYINT UNSIGNED',
				),
				3   => array(
					'field_name' => 'data',
					'scale'      => 1,
					'offset'     => 0,
					'units'      => '',
					'raw'        => 'INT UNSIGNED',
					'metric'     => 'INT UNSIGNED',
					'statute'    => 'INT UNSIGNED',
				),
				4   => array(
					'field_name' => 'event_group',
					'scale'      => 1,
					'offset'     => 0,
					'units'      => '',
					'raw'        => 'TINYINT UNSIGNED',
					'metric'     => 'TINYINT UNSIGNED',
					'statute'    => 'TINYINT UNSIGNED',
				),
				253 => array(
					'field_name' => 'timestamp',
					'scale'      => 1,
					'offset'     => 0,
					'units'      => 's',
					'raw'        => 'INT UNSIGNED',
					'metric'     => 'INT UNSIGNED',
					'statute'    => 'INT UNSIGNED',
				),
			),
		),

		23  => array(
			'mesg_name'   => 'device_info',
			'field_defns' => array(
				0   => array(
					'field_name' => 'device_index',
					'scale'      => 1,
					'offset'     => 0,
					'units'      => '',
					'raw'        => 'TINYINT UNSIGNED',
					'metric'     => 'TINYINT UNSIGNED',
					'statute'    => 'TINYINT UNSIGNED',
				),
				1   => array(
					'field_name' => 'device_type',
					'scale'      => 1,
					'offset'     => 0,
					'units'      => '',
					'raw'        => 'TINYINT UNSIGNED',
					'metric'     => 'TINYINT UNSIGNED',
					'statute'    => 'TINYINT UNSIGNED',
				),
				2   => array(
					'field_name' => 'manufacturer',
					'scale'      => 1,
					'offset'     => 0,
					'units'      => '',
					'raw'        => 'SMALLINT UNSIGNED',
					'metric'     => 'SMALLINT UNSIGNED',
					'statute'    => 'SMALLINT UNSIGNED',
				),
				3   => array(
					'field_name' => 'serial_number',
					'scale'      => 1,
					'offset'     => 0,
					'units'      => '',
					'raw'        => 'INT UNSIGNED',
					'metric'     => 'INT UNSIGNED',
					'statute'    => 'INT UNSIGNED',
				),
				4   => array(
					'field_name' => 'product',
					'scale'      => 1,
					'offset'     => 0,
					'units'      => '',
					'raw'        => 'SMALLINT UNSIGNED',
					'metric'     => 'SMALLINT UNSIGNED',
					'statute'    => 'SMALLINT UNSIGNED',
				),
				5   => array(
					'field_name' => 'software_version',
					'scale'      => 1,
					'offset'     => 0,
					'units'      => '',
					'raw'        => 'DECIMAL(5,2)',
					'metric'     => 'DECIMAL(5,2)',
					'statute'    => 'DECIMAL(5,2)',
				),
				6   => array(
					'field_name' => 'hardware_version',
					'scale'      => 1,
					'offset'     => 0,
					'units'      => '',
					'raw'        => 'TINYINT UNSIGNED',
					'metric'     => 'TINYINT UNSIGNED',
					'statute'    => 'TINYINT UNSIGNED',
				),
				7   => array(
					'field_name' => 'cum_operating_time',
					'scale'      => 1,
					'offset'     => 0,
					'units'      => '',
					'raw'        => 'INT UNSIGNED',
					'metric'     => 'INT UNSIGNED',
					'statute'    => 'INT UNSIGNED',
				),
				10  => array(
					'field_name' => 'battery_voltage',
					'scale'      => 1,
					'offset'     => 0,
					'units'      => '',
					'raw'        => 'DECIMAL(11,8)',
					'metric'     => 'DECIMAL(11,8)',
					'statute'    => 'DECIMAL(11,8)',
				),
				11  => array(
					'field_name' => 'battery_status',
					'scale'      => 1,
					'offset'     => 0,
					'units'      => '',
					'raw'        => 'TINYINT UNSIGNED',
					'metric'     => 'TINYINT UNSIGNED',
					'statute'    => 'TINYINT UNSIGNED',
				),
				20  => array(
					'field_name' => 'ant_transmission_type',
					'scale'      => 1,
					'offset'     => 0,
					'units'      => '',
					'raw'        => 'TINYINT UNSIGNED',
					'metric'     => 'TINYINT UNSIGNED',
					'statute'    => 'TINYINT UNSIGNED',
				),
				21  => array(
					'field_name' => 'ant_device_number',
					'scale'      => 1,
					'offset'     => 0,
					'units'      => '',
					'raw'        => 'SMALLINT UNSIGNED',
					'metric'     => 'SMALLINT UNSIGNED',
					'statute'    => 'SMALLINT UNSIGNED',
				),
				22  => array(
					'field_name' => 'ant_network',
					'scale'      => 1,
					'offset'     => 0,
					'units'      => '',
					'raw'        => 'TINYINT UNSIGNED',
					'metric'     => 'TINYINT UNSIGNED',
					'statute'    => 'TINYINT UNSIGNED',
				),
				25  => array(
					'field_name' => 'source_type',
					'scale'      => 1,
					'offset'     => 0,
					'units'      => '',
					'raw'        => 'TINYINT UNSIGNED',
					'metric'     => 'TINYINT UNSIGNED',
					'statute'    => 'TINYINT UNSIGNED',
				),
				253 => array(
					'field_name' => 'timestamp',
					'scale'      => 1,
					'offset'     => 0,
					'units'      => 's',
					'raw'        => 'INT UNSIGNED',
					'metric'     => 'INT UNSIGNED',
					'statute'    => 'INT UNSIGNED',
				),
			),
		),

		34  => array(
			'mesg_name'   => 'activity',
			'field_defns' => array(
				0   => array(
					'field_name' => 'total_timer_time',
					'scale'      => 1000,
					'offset'     => 0,
					'units'      => 's',
					'raw'        => 'DECIMAL(10,3)',
					'metric'     => 'DECIMAL(10,3)',
					'statute'    => 'DECIMAL(10,3)',
				),
				1   => array(
					'field_name' => 'num_sessions',
					'scale'      => 1,
					'offset'     => 0,
					'units'      => '',
					'raw'        => 'SMALLINT UNSIGNED',
					'metric'     => 'SMALLINT UNSIGNED',
					'statute'    => 'SMALLINT UNSIGNED',
				),
				2   => array(
					'field_name' => 'type',
					'scale'      => 1,
					'offset'     => 0,
					'units'      => '',
					'raw'        => 'TINYINT UNSIGNED',
					'metric'     => 'TINYINT UNSIGNED',
					'statute'    => 'TINYINT UNSIGNED',
				),
				3   => array(
					'field_name' => 'event',
					'scale'      => 1,
					'offset'     => 0,
					'units'      => '',
					'raw'        => 'TINYINT UNSIGNED',
					'metric'     => 'TINYINT UNSIGNED',
					'statute'    => 'TINYINT UNSIGNED',
				),
				4   => array(
					'field_name' => 'event_type',
					'scale'      => 1,
					'offset'     => 0,
					'units'      => '',
					'raw'        => 'TINYINT UNSIGNED',
					'metric'     => 'TINYINT UNSIGNED',
					'statute'    => 'TINYINT UNSIGNED',
				),
				5   => array(
					'field_name' => 'local_timestamp',
					'scale'      => 1,
					'offset'     => 0,
					'units'      => '',
					'raw'        => 'INT UNSIGNED',
					'metric'     => 'INT UNSIGNED',
					'statute'    => 'INT UNSIGNED',
				),
				6   => array(
					'field_name' => 'event_group',
					'scale'      => 1,
					'offset'     => 0,
					'units'      => '',
					'raw'        => 'TINYINT UNSIGNED',
					'metric'     => 'TINYINT UNSIGNED',
					'statute'    => 'TINYINT UNSIGNED',
				),
				253 => array(
					'field_name' => 'timestamp',
					'scale'      => 1,
					'offset'     => 0,
					'units'      => 's',
					'raw'        => 'INT UNSIGNED',
					'metric'     => 'INT UNSIGNED',
					'statute'    => 'INT UNSIGNED',
				),
			),
		),

		49  => array(
			'mesg_name'   => 'file_creator',
			'field_defns' => array(
				0 => array(
					'field_name' => 'software_version',
					'scale'      => 1,
					'offset'     => 0,
					'units'      => '',
					'raw'        => 'SMALLINT UNSIGNED',
					'metric'     => 'SMALLINT UNSIGNED',
					'statute'    => 'SMALLINT UNSIGNED',
				),
				1 => array(
					'field_name' => 'hardware_version',
					'scale'      => 1,
					'offset'     => 0,
					'units'      => '',
					'raw'        => 'TINYINT UNSIGNED',
					'metric'     => 'TINYINT UNSIGNED',
					'statute'    => 'TINYINT UNSIGNED',
				),
			),
		),

		78  => array(
			'mesg_name'   => 'hrv',
			'field_defns' => array(
				0 => array(
					'field_name' => 'times',
					'scale'      => 1000,
					'offset'     => 0,
					'units'      => 's',
					'raw'        => 'VARCHAR(64)',
					'metric'     => 'VARCHAR(64)',
					'statute'    => 'VARCHAR(64)',
				),
			),
		),

		101 => array(
			'mesg_name'   => 'length',
			'field_defns' => array(
				0   => array(
					'field_name' => 'event',
					'scale'      => 1,
					'offset'     => 0,
					'units'      => '',
					'raw'        => 'TINYINT UNSIGNED',
					'metric'     => 'TINYINT UNSIGNED',
					'statute'    => 'TINYINT UNSIGNED',
				),
				1   => array(
					'field_name' => 'event_type',
					'scale'      => 1,
					'offset'     => 0,
					'units'      => '',
					'raw'        => 'TINYINT UNSIGNED',
					'metric'     => 'TINYINT UNSIGNED',
					'statute'    => 'TINYINT UNSIGNED',
				),
				2   => array(
					'field_name' => 'start_time',
					'scale'      => 1,
					'offset'     => 0,
					'units'      => '',
					'raw'        => 'INT UNSIGNED',
					'metric'     => 'INT UNSIGNED',
					'statute'    => 'INT UNSIGNED',
				),
				3   => array(
					'field_name' => 'total_elapsed_time',
					'scale'      => 1000,
					'offset'     => 0,
					'units'      => 's',
					'raw'        => 'DECIMAL(10,3)',
					'metric'     => 'DECIMAL(10,3)',
					'statute'    => 'DECIMAL(10,3)',
				),
				4   => array(
					'field_name' => 'total_timer_time',
					'scale'      => 1000,
					'offset'     => 0,
					'units'      => 's',
					'raw'        => 'DECIMAL(10,3)',
					'metric'     => 'DECIMAL(10,3)',
					'statute'    => 'DECIMAL(10,3)',
				),
				5   => array(
					'field_name' => 'total_strokes',
					'scale'      => 1,
					'offset'     => 0,
					'units'      => 'strokes',
					'raw'        => 'SMALLINT UNSIGNED',
					'metric'     => 'SMALLINT UNSIGNED',
					'statute'    => 'SMALLINT UNSIGNED',
				),
				6   => array(
					'field_name' => 'avg_speed',
					'scale'      => 1000,
					'offset'     => 0,
					'units'      => 'm/s',
					'raw'        => 'DECIMAL(5,3)',
					'metric'     => 'DECIMAL(7,4)',
					'statute'    => 'DECIMAL(7,4)',
				),
				7   => array(
					'field_name' => 'swim_stroke',
					'scale'      => 1,
					'offset'     => 0,
					'units'      => 'swim_stroke',
					'raw'        => 'TINYINT UNSIGNED',
					'metric'     => 'TINYINT UNSIGNED',
					'statute'    => 'TINYINT UNSIGNED',
				),
				9   => array(
					'field_name' => 'avg_swimming_cadence',
					'scale'      => 1,
					'offset'     => 0,
					'units'      => 'strokes/min',
					'raw'        => 'TINYINT UNSIGNED',
					'metric'     => 'TINYINT UNSIGNED',
					'statute'    => 'TINYINT UNSIGNED',
				),
				10  => array(
					'field_name' => 'event_group',
					'scale'      => 1,
					'offset'     => 0,
					'units'      => '',
					'raw'        => 'TINYINT UNSIGNED',
					'metric'     => 'TINYINT UNSIGNED',
					'statute'    => 'TINYINT UNSIGNED',
				),
				11  => array(
					'field_name' => 'total_calories',
					'scale'      => 1,
					'offset'     => 0,
					'units'      => 'kcal',
					'raw'        => 'SMALLINT UNSIGNED',
					'metric'     => 'SMALLINT UNSIGNED',
					'statute'    => 'SMALLINT UNSIGNED',
				),
				12  => array(
					'field_name' => 'length_type',
					'scale'      => 1,
					'offset'     => 0,
					'units'      => '',
					'raw'        => 'TINYINT UNSIGNED',
					'metric'     => 'TINYINT UNSIGNED',
					'statute'    => 'TINYINT UNSIGNED',
				),
				253 => array(
					'field_name' => 'timestamp',
					'scale'      => 1,
					'offset'     => 0,
					'units'      => 's',
					'raw'        => 'INT UNSIGNED',
					'metric'     => 'INT UNSIGNED',
					'statute'    => 'INT UNSIGNED',
				),
				254 => array(
					'field_name' => 'message_index',
					'scale'      => 1,
					'offset'     => 0,
					'units'      => '',
					'raw'        => 'SMALLINT UNSIGNED',
					'metric'     => 'SMALLINT UNSIGNED',
					'statute'    => 'SMALLINT UNSIGNED',
				),
			),
		),

		// 'event_timestamp' and 'event_timestamp_12' should have scale of 1024 but due to floating point rounding errors.
		// These are manually divided by 1024 later in the processHrMessages() function.
		132 => array(
			'mesg_name'   => 'hr',
			'field_defns' => array(
				0   => array(
					'field_name' => 'fractional_timestamp',
					'scale'      => 32768,
					'offset'     => 0,
					'units'      => 's',
					'raw'        => 'DOUBLE',
					'metric'     => 'DOUBLE',
					'statute'    => 'DOUBLE',
				),
				1   => array(
					'field_name' => 'time256',
					'scale'      => 256,
					'offset'     => 0,
					'units'      => 's',
					'raw'        => 'DOUBLE',
					'metric'     => 'DOUBLE',
					'statute'    => 'DOUBLE',
				),
				6   => array(
					'field_name' => 'filtered_bpm',
					'scale'      => 1,
					'offset'     => 0,
					'units'      => 'bpm',
					'raw'        => 'TINYINT UNSIGNED',
					'metric'     => 'TINYINT UNSIGNED',
					'statute'    => 'TINYINT UNSIGNED',
				),
				9   => array(
					'field_name' => 'event_timestamp',
					'scale'      => 1,
					'offset'     => 0,
					'units'      => 's',
					'raw'        => 'DOUBLE',
					'metric'     => 'DOUBLE',
					'statute'    => 'DOUBLE',
				),
				10  => array(
					'field_name' => 'event_timestamp_12',
					'scale'      => 1,
					'offset'     => 0,
					'units'      => 's',
					'raw'        => 'SMALLINT UNSIGNED',
					'metric'     => 'SMALLINT UNSIGNED',
					'statute'    => 'SMALLINT UNSIGNED',
				),
				253 => array(
					'field_name' => 'timestamp',
					'scale'      => 1,
					'offset'     => 0,
					'units'      => 's',
					'raw'        => 'INT UNSIGNED',
					'metric'     => 'INT UNSIGNED',
					'statute'    => 'INT UNSIGNED',
				),
			),
		),

		142 => array(
			'mesg_name'   => 'segment_lap',
			'field_defns' => array(
				0   => array(
					'field_name' => 'event',
					'scale'      => 1,
					'offset'     => 0,
					'units'      => '',
					'raw'        => 'TINYINT UNSIGNED',
					'metric'     => 'TINYINT UNSIGNED',
					'statute'    => 'TINYINT UNSIGNED',
				),
				1   => array(
					'field_name' => 'event_type',
					'scale'      => 1,
					'offset'     => 0,
					'units'      => '',
					'raw'        => 'TINYINT UNSIGNED',
					'metric'     => 'TINYINT UNSIGNED',
					'statute'    => 'TINYINT UNSIGNED',
				),
				2   => array(
					'field_name' => 'start_time',
					'scale'      => 1,
					'offset'     => 0,
					'units'      => '',
					'raw'        => 'INT UNSIGNED',
					'metric'     => 'INT UNSIGNED',
					'statute'    => 'INT UNSIGNED',
				),
				3   => array(
					'field_name' => 'start_position_lat',
					'scale'      => 1,
					'offset'     => 0,
					'units'      => 'semicircles',
					'raw'        => 'INT',
					'metric'     => 'DECIMAL(10,7)',
					'statute'    => 'DECIMAL(10,7)',
				),
				4   => array(
					'field_name' => 'start_position_long',
					'scale'      => 1,
					'offset'     => 0,
					'units'      => 'semicircles',
					'raw'        => 'INT',
					'metric'     => 'DECIMAL(11,7)',
					'statute'    => 'DECIMAL(11,7)',
				),
				5   => array(
					'field_name' => 'end_position_lat',
					'scale'      => 1,
					'offset'     => 0,
					'units'      => 'semicircles',
					'raw'        => 'INT',
					'metric'     => 'DECIMAL(10,7)',
					'statute'    => 'DECIMAL(10,7)',
				),
				6   => array(
					'field_name' => 'end_position_long',
					'scale'      => 1,
					'offset'     => 0,
					'units'      => 'semicircles',
					'raw'        => 'INT',
					'metric'     => 'DECIMAL(11,7)',
					'statute'    => 'DECIMAL(11,7)',
				),
				7   => array(
					'field_name' => 'total_elapsed_time',
					'scale'      => 1000,
					'offset'     => 0,
					'units'      => 's',
					'raw'        => 'DECIMAL(10,3)',
					'metric'     => 'DECIMAL(10,3)',
					'statute'    => 'DECIMAL(10,3)',
				),
				8   => array(
					'field_name' => 'total_timer_time',
					'scale'      => 1000,
					'offset'     => 0,
					'units'      => 's',
					'raw'        => 'DECIMAL(10,3)',
					'metric'     => 'DECIMAL(10,3)',
					'statute'    => 'DECIMAL(10,3)',
				),
				9   => array(
					'field_name' => 'total_distance',
					'scale'      => 100,
					'offset'     => 0,
					'units'      => 'm',
					'raw'        => 'DECIMAL(10,2)',
					'metric'     => 'DECIMAL(10,5)',
					'statute'    => 'DECIMAL(10,5)',
				),
				10  => array(
					'field_name' => 'total_cycles',
					'scale'      => 1,
					'offset'     => 0,
					'units'      => 'cycles',
					'raw'        => 'INT UNSIGNED',
					'metric'     => 'INT UNSIGNED',
					'statute'    => 'INT UNSIGNED',
				),
				11  => array(
					'field_name' => 'total_calories',
					'scale'      => 1,
					'offset'     => 0,
					'units'      => 'kcal',
					'raw'        => 'SMALLINT UNSIGNED',
					'metric'     => 'SMALLINT UNSIGNED',
					'statute'    => 'SMALLINT UNSIGNED',
				),
				12  => array(
					'field_name' => 'total_fat_calories',
					'scale'      => 1,
					'offset'     => 0,
					'units'      => 'kcal',
					'raw'        => 'SMALLINT UNSIGNED',
					'metric'     => 'SMALLINT UNSIGNED',
					'statute'    => 'SMALLINT UNSIGNED',
				),
				13  => array(
					'field_name' => 'avg_speed',
					'scale'      => 1000,
					'offset'     => 0,
					'units'      => 'm/s',
					'raw'        => 'DECIMAL(5,3)',
					'metric'     => 'DECIMAL(7,4)',
					'statute'    => 'DECIMAL(7,4)',
				),
				14  => array(
					'field_name' => 'max_speed',
					'scale'      => 1000,
					'offset'     => 0,
					'units'      => 'm/s',
					'raw'        => 'DECIMAL(5,3)',
					'metric'     => 'DECIMAL(7,4)',
					'statute'    => 'DECIMAL(7,4)',
				),
				15  => array(
					'field_name' => 'avg_heart_rate',
					'scale'      => 1,
					'offset'     => 0,
					'units'      => 'bpm',
					'raw'        => 'TINYINT UNSIGNED',
					'metric'     => 'TINYINT UNSIGNED',
					'statute'    => 'TINYINT UNSIGNED',
				),
				16  => array(
					'field_name' => 'max_heart_rate',
					'scale'      => 1,
					'offset'     => 0,
					'units'      => 'bpm',
					'raw'        => 'TINYINT UNSIGNED',
					'metric'     => 'TINYINT UNSIGNED',
					'statute'    => 'TINYINT UNSIGNED',
				),
				17  => array(
					'field_name' => 'avg_cadence',
					'scale'      => 1,
					'offset'     => 0,
					'units'      => 'rpm',
					'raw'        => 'TINYINT UNSIGNED',
					'metric'     => 'TINYINT UNSIGNED',
					'statute'    => 'TINYINT UNSIGNED',
				),
				18  => array(
					'field_name' => 'max_cadence',
					'scale'      => 1,
					'offset'     => 0,
					'units'      => 'rpm',
					'raw'        => 'TINYINT UNSIGNED',
					'metric'     => 'TINYINT UNSIGNED',
					'statute'    => 'TINYINT UNSIGNED',
				),
				19  => array(
					'field_name' => 'avg_power',
					'scale'      => 1,
					'offset'     => 0,
					'units'      => 'watts',
					'raw'        => 'SMALLINT UNSIGNED',
					'metric'     => 'SMALLINT UNSIGNED',
					'statute'    => 'SMALLINT UNSIGNED',
				),
				20  => array(
					'field_name' => 'max_power',
					'scale'      => 1,
					'offset'     => 0,
					'units'      => 'watts',
					'raw'        => 'SMALLINT UNSIGNED',
					'metric'     => 'SMALLINT UNSIGNED',
					'statute'    => 'SMALLINT UNSIGNED',
				),
				21  => array(
					'field_name' => 'total_ascent',
					'scale'      => 1,
					'offset'     => 0,
					'units'      => 'm',
					'raw'        => 'SMALLINT UNSIGNED',
					'metric'     => 'SMALLINT UNSIGNED',
					'statute'    => 'DECIMAL(7,1)',
				),
				22  => array(
					'field_name' => 'total_descent',
					'scale'      => 1,
					'offset'     => 0,
					'units'      => 'm',
					'raw'        => 'SMALLINT UNSIGNED',
					'metric'     => 'SMALLINT UNSIGNED',
					'statute'    => 'DECIMAL(7,1)',
				),
				23  => array(
					'field_name' => 'sport',
					'scale'      => 1,
					'offset'     => 0,
					'units'      => '',
					'raw'        => 'TINYINT UNSIGNED',
					'metric'     => 'TINYINT UNSIGNED',
					'statute'    => 'TINYINT UNSIGNED',
				),
				24  => array(
					'field_name' => 'event_group',
					'scale'      => 1,
					'offset'     => 0,
					'units'      => '',
					'raw'        => 'TINYINT UNSIGNED',
					'metric'     => 'TINYINT UNSIGNED',
					'statute'    => 'TINYINT UNSIGNED',
				),
				25  => array(
					'field_name' => 'nec_lat',
					'scale'      => 1,
					'offset'     => 0,
					'units'      => 'semicircles',
					'raw'        => 'INT',
					'metric'     => 'DECIMAL(10,7)',
					'statute'    => 'DECIMAL(10,7)',
				),
				26  => array(
					'field_name' => 'nec_long',
					'scale'      => 1,
					'offset'     => 0,
					'units'      => 'semicircles',
					'raw'        => 'INT',
					'metric'     => 'DECIMAL(11,7)',
					'statute'    => 'DECIMAL(11,7)',
				),
				27  => array(
					'field_name' => 'swc_lat',
					'scale'      => 1,
					'offset'     => 0,
					'units'      => 'semicircles',
					'raw'        => 'INT',
					'metric'     => 'DECIMAL(10,7)',
					'statute'    => 'DECIMAL(10,7)',
				),
				28  => array(
					'field_name' => 'swc_long',
					'scale'      => 1,
					'offset'     => 0,
					'units'      => 'semicircles',
					'raw'        => 'INT',
					'metric'     => 'DECIMAL(11,7)',
					'statute'    => 'DECIMAL(11,7)',
				),
				29  => array(
					'field_name' => 'name',
					'scale'      => 1,
					'offset'     => 0,
					'units'      => '',
					'raw'        => 'VARCHAR(16)',
					'metric'     => 'VARCHAR(16)',
					'statute'    => 'VARCHAR(16)',
				),
				30  => array(
					'field_name' => 'normalized_power',
					'scale'      => 1,
					'offset'     => 0,
					'units'      => 'watts',
					'raw'        => 'SMALLINT UNSIGNED',
					'metric'     => 'SMALLINT UNSIGNED',
					'statute'    => 'SMALLINT UNSIGNED',
				),
				31  => array(
					'field_name' => 'left_right_balance',
					'scale'      => 1,
					'offset'     => 0,
					'units'      => '',
					'raw'        => 'SMALLINT UNSIGNED',
					'metric'     => 'SMALLINT UNSIGNED',
					'statute'    => 'SMALLINT UNSIGNED',
				),
				32  => array(
					'field_name' => 'sub_sport',
					'scale'      => 1,
					'offset'     => 0,
					'units'      => '',
					'raw'        => 'TINYINT UNSIGNED',
					'metric'     => 'TINYINT UNSIGNED',
					'statute'    => 'TINYINT UNSIGNED',
				),
				33  => array(
					'field_name' => 'total_work',
					'scale'      => 1,
					'offset'     => 0,
					'units'      => 'J',
					'raw'        => 'INT UNSIGNED',
					'metric'     => 'INT UNSIGNED',
					'statute'    => 'INT UNSIGNED',
				),
				58  => array(
					'field_name' => 'sport_event',
					'scale'      => 1,
					'offset'     => 0,
					'units'      => '',
					'raw'        => 'TINYINT UNSIGNED',
					'metric'     => 'TINYINT UNSIGNED',
					'statute'    => 'TINYINT UNSIGNED',
				),
				59  => array(
					'field_name' => 'avg_left_torque_effectiveness',
					'scale'      => 2,
					'offset'     => 0,
					'units'      => 'percent',
					'raw'        => 'DECIMAL(4,1)',
					'metric'     => 'DECIMAL(4,1)',
					'statute'    => 'DECIMAL(4,1)',
				),
				60  => array(
					'field_name' => 'avg_right_torque_effectiveness',
					'scale'      => 2,
					'offset'     => 0,
					'units'      => 'percent',
					'raw'        => 'DECIMAL(4,1)',
					'metric'     => 'DECIMAL(4,1)',
					'statute'    => 'DECIMAL(4,1)',
				),
				61  => array(
					'field_name' => 'avg_left_pedal_smoothness',
					'scale'      => 2,
					'offset'     => 0,
					'units'      => 'percent',
					'raw'        => 'DECIMAL(4,1)',
					'metric'     => 'DECIMAL(4,1)',
					'statute'    => 'DECIMAL(4,1)',
				),
				62  => array(
					'field_name' => 'avg_right_pedal_smoothness',
					'scale'      => 2,
					'offset'     => 0,
					'units'      => 'percent',
					'raw'        => 'DECIMAL(4,1)',
					'metric'     => 'DECIMAL(4,1)',
					'statute'    => 'DECIMAL(4,1)',
				),
				63  => array(
					'field_name' => 'avg_combined_pedal_smoothness',
					'scale'      => 2,
					'offset'     => 0,
					'units'      => 'percent',
					'raw'        => 'DECIMAL(4,1)',
					'metric'     => 'DECIMAL(4,1)',
					'statute'    => 'DECIMAL(4,1)',
				),
				64  => array(
					'field_name' => 'status',
					'scale'      => 1,
					'offset'     => 0,
					'units'      => '',
					'raw'        => 'TINYINT UNSIGNED',
					'metric'     => 'TINYINT UNSIGNED',
					'statute'    => 'TINYINT UNSIGNED',
				),
				65  => array(
					'field_name' => 'uuid',
					'scale'      => 1,
					'offset'     => 0,
					'units'      => '',
					'raw'        => 'VARCHAR(16)',
					'metric'     => 'VARCHAR(16)',
					'statute'    => 'VARCHAR(16)',
				),
				66  => array(
					'field_name' => 'avg_fractional_cadence',
					'scale'      => 128,
					'offset'     => 0,
					'units'      => 'rpm',
					'raw'        => 'DOUBLE',
					'metric'     => 'DOUBLE',
					'statute'    => 'DOUBLE',
				),
				67  => array(
					'field_name' => 'max_fractional_cadence',
					'scale'      => 128,
					'offset'     => 0,
					'units'      => 'rpm',
					'raw'        => 'DOUBLE',
					'metric'     => 'DOUBLE',
					'statute'    => 'DOUBLE',
				),
				68  => array(
					'field_name' => 'total_fractional_cycles',
					'scale'      => 128,
					'offset'     => 0,
					'units'      => 'cycles',
					'raw'        => 'DOUBLE',
					'metric'     => 'DOUBLE',
					'statute'    => 'DOUBLE',
				),
				69  => array(
					'field_name' => 'front_gear_shift_count',
					'scale'      => 1,
					'offset'     => 0,
					'units'      => '',
					'raw'        => 'SMALLINT UNSIGNED',
					'metric'     => 'SMALLINT UNSIGNED',
					'statute'    => 'SMALLINT UNSIGNED',
				),
				70  => array(
					'field_name' => 'rear_gear_shift_count',
					'scale'      => 1,
					'offset'     => 0,
					'units'      => '',
					'raw'        => 'SMALLINT UNSIGNED',
					'metric'     => 'SMALLINT UNSIGNED',
					'statute'    => 'SMALLINT UNSIGNED',
				),
				71  => array(
					'field_name' => 'time_standing',
					'scale'      => 1000,
					'offset'     => 0,
					'units'      => 's',
					'raw'        => 'DECIMAL(10,3)',
					'metric'     => 'DECIMAL(10,3)',
					'statute'    => 'DECIMAL(10,3)',
				),
				72  => array(
					'field_name' => 'stand_count',
					'scale'      => 1,
					'offset'     => 0,
					'units'      => '',
					'raw'        => 'SMALLINT UNSIGNED',
					'metric'     => 'SMALLINT UNSIGNED',
					'statute'    => 'SMALLINT UNSIGNED',
				),
				73  => array(
					'field_name' => 'avg_left_pco',
					'scale'      => 1,
					'offset'     => 0,
					'units'      => 'mm',
					'raw'        => 'TINYINT',
					'metric'     => 'TINYINT',
					'statute'    => 'TINYINT',
				),
				74  => array(
					'field_name' => 'avg_right_pco',
					'scale'      => 1,
					'offset'     => 0,
					'units'      => 'mm',
					'raw'        => 'TINYINT',
					'metric'     => 'TINYINT',
					'statute'    => 'TINYINT',
				),
				75  => array(
					'field_name' => 'avg_left_power_phase',
					'scale'      => 0.7111111,
					'offset'     => 0,
					'units'      => 'degrees',
					'raw'        => 'FLOAT',
					'metric'     => 'FLOAT',
					'statute'    => 'FLOAT',
				),
				76  => array(
					'field_name' => 'avg_left_power_phase_peak',
					'scale'      => 0.7111111,
					'offset'     => 0,
					'units'      => 'degrees',
					'raw'        => 'FLOAT',
					'metric'     => 'FLOAT',
					'statute'    => 'FLOAT',
				),
				77  => array(
					'field_name' => 'avg_right_power_phase',
					'scale'      => 0.7111111,
					'offset'     => 0,
					'units'      => 'degrees',
					'raw'        => 'FLOAT',
					'metric'     => 'FLOAT',
					'statute'    => 'FLOAT',
				),
				78  => array(
					'field_name' => 'avg_right_power_phase_peak',
					'scale'      => 0.7111111,
					'offset'     => 0,
					'units'      => 'degrees',
					'raw'        => 'FLOAT',
					'metric'     => 'FLOAT',
					'statute'    => 'FLOAT',
				),
				79  => array(
					'field_name' => 'avg_power_position',
					'scale'      => 1,
					'offset'     => 0,
					'units'      => 'watts',
					'raw'        => 'SMALLINT UNSIGNED',
					'metric'     => 'SMALLINT UNSIGNED',
					'statute'    => 'SMALLINT UNSIGNED',
				),
				80  => array(
					'field_name' => 'max_power_position',
					'scale'      => 1,
					'offset'     => 0,
					'units'      => 'watts',
					'raw'        => 'SMALLINT UNSIGNED',
					'metric'     => 'SMALLINT UNSIGNED',
					'statute'    => 'SMALLINT UNSIGNED',
				),
				81  => array(
					'field_name' => 'avg_cadence_position',
					'scale'      => 1,
					'offset'     => 0,
					'units'      => 'rpm',
					'raw'        => 'SMALLINT UNSIGNED',
					'metric'     => 'SMALLINT UNSIGNED',
					'statute'    => 'SMALLINT UNSIGNED',
				),
				82  => array(
					'field_name' => 'max_cadence_position',
					'scale'      => 1,
					'offset'     => 0,
					'units'      => 'rpm',
					'raw'        => 'SMALLINT UNSIGNED',
					'metric'     => 'SMALLINT UNSIGNED',
					'statute'    => 'SMALLINT UNSIGNED',
				),
				253 => array(
					'field_name' => 'timestamp',
					'scale'      => 1,
					'offset'     => 0,
					'units'      => 's',
					'raw'        => 'INT UNSIGNED',
					'metric'     => 'INT UNSIGNED',
					'statute'    => 'INT UNSIGNED',
				),
				254 => array(
					'field_name' => 'message_index',
					'scale'      => 1,
					'offset'     => 0,
					'units'      => '',
					'raw'        => 'SMALLINT UNSIGNED',
					'metric'     => 'SMALLINT UNSIGNED',
					'statute'    => 'SMALLINT UNSIGNED',
				),
			),
		),

		206 => array(
			'mesg_name'   => 'field_description',
			'field_defns' => array(
				0  => array(
					'field_name' => 'developer_data_index',
					'scale'      => 1,
					'offset'     => 0,
					'units'      => '',
					'raw'        => 'TINYINT UNSIGNED',
					'metric'     => 'TINYINT UNSIGNED',
					'statute'    => 'TINYINT UNSIGNED',
				),
				1  => array(
					'field_name' => 'field_definition_number',
					'scale'      => 1,
					'offset'     => 0,
					'units'      => '',
					'raw'        => 'TINYINT UNSIGNED',
					'metric'     => 'TINYINT UNSIGNED',
					'statute'    => 'TINYINT UNSIGNED',
				),
				2  => array(
					'field_name' => 'fit_base_type_id',
					'scale'      => 1,
					'offset'     => 0,
					'units'      => '',
					'raw'        => 'TINYINT UNSIGNED',
					'metric'     => 'TINYINT UNSIGNED',
					'statute'    => 'TINYINT UNSIGNED',
				),
				3  => array(
					'field_name' => 'field_name',
					'scale'      => 1,
					'offset'     => 0,
					'units'      => '',
					'raw'        => 'VARCHAR(16)',
					'metric'     => 'VARCHAR(16)',
					'statute'    => 'VARCHAR(16)',
				),
				4  => array(
					'field_name' => 'array',
					'scale'      => 1,
					'offset'     => 0,
					'units'      => '',
					'raw'        => 'TINYINT UNSIGNED',
					'metric'     => 'TINYINT UNSIGNED',
					'statute'    => 'TINYINT UNSIGNED',
				),
				5  => array(
					'field_name' => 'components',
					'scale'      => 1,
					'offset'     => 0,
					'units'      => '',
					'raw'        => 'VARCHAR(16)',
					'metric'     => 'VARCHAR(16)',
					'statute'    => 'VARCHAR(16)',
				),
				6  => array(
					'field_name' => 'scale',
					'scale'      => 1,
					'offset'     => 0,
					'units'      => '',
					'raw'        => 'TINYINT UNSIGNED',
					'metric'     => 'TINYINT UNSIGNED',
					'statute'    => 'TINYINT UNSIGNED',
				),
				7  => array(
					'field_name' => 'offset',
					'scale'      => 1,
					'offset'     => 0,
					'units'      => '',
					'raw'        => 'TINYINT',
					'metric'     => 'TINYINT',
					'statute'    => 'TINYINT',
				),
				8  => array(
					'field_name' => 'units',
					'scale'      => 1,
					'offset'     => 0,
					'units'      => '',
					'raw'        => 'VARCHAR(16)',
					'metric'     => 'VARCHAR(16)',
					'statute'    => 'VARCHAR(16)',
				),
				9  => array(
					'field_name' => 'bits',
					'scale'      => 1,
					'offset'     => 0,
					'units'      => '',
					'raw'        => 'VARCHAR(16)',
					'metric'     => 'VARCHAR(16)',
					'statute'    => 'VARCHAR(16)',
				),
				10 => array(
					'field_name' => 'accumulate',
					'scale'      => 1,
					'offset'     => 0,
					'units'      => '',
					'raw'        => 'VARCHAR(16)',
					'metric'     => 'VARCHAR(16)',
					'statute'    => 'VARCHAR(16)',
				),
				13 => array(
					'field_name' => 'fit_base_unit_id',
					'scale'      => 1,
					'offset'     => 0,
					'units'      => '',
					'raw'        => 'SMALLINT UNSIGNED',
					'metric'     => 'SMALLINT UNSIGNED',
					'statute'    => 'SMALLINT UNSIGNED',
				),
				14 => array(
					'field_name' => 'native_mesg_num',
					'scale'      => 1,
					'offset'     => 0,
					'units'      => '',
					'raw'        => 'SMALLINT UNSIGNED',
					'metric'     => 'SMALLINT UNSIGNED',
					'statute'    => 'SMALLINT UNSIGNED',
				),
				15 => array(
					'field_name' => 'native_field_num',
					'scale'      => 1,
					'offset'     => 0,
					'units'      => '',
					'raw'        => 'TINYINT UNSIGNED',
					'metric'     => 'TINYINT UNSIGNED',
					'statute'    => 'TINYINT UNSIGNED',
				),
			),
		),

		207 => array(
			'mesg_name'   => 'developer_data_id',
			'field_defns' => array(
				0 => array(
					'field_name' => 'developer_id',
					'scale'      => 1,
					'offset'     => 0,
					'units'      => '',
					'raw'        => 'SMALLINT UNSIGNED',
					'metric'     => 'SMALLINT UNSIGNED',
					'statute'    => 'SMALLINT UNSIGNED',
				),
				1 => array(
					'field_name' => 'application_id',
					'scale'      => 1,
					'offset'     => 0,
					'units'      => '',
					'raw'        => 'SMALLINT UNSIGNED',
					'metric'     => 'SMALLINT UNSIGNED',
					'statute'    => 'SMALLINT UNSIGNED',
				),
				2 => array(
					'field_name' => 'manufacturer_id',
					'scale'      => 1,
					'offset'     => 0,
					'units'      => '',
					'raw'        => 'SMALLINT UNSIGNED',
					'metric'     => 'SMALLINT UNSIGNED',
					'statute'    => 'SMALLINT UNSIGNED',
				),
				3 => array(
					'field_name' => 'developer_data_index',
					'scale'      => 1,
					'offset'     => 0,
					'units'      => '',
					'raw'        => 'TINYINT UNSIGNED',
					'metric'     => 'TINYINT UNSIGNED',
					'statute'    => 'TINYINT UNSIGNED',
				),
				4 => array(
					'field_name' => 'application_version',
					'scale'      => 1,
					'offset'     => 0,
					'units'      => '',
					'raw'        => 'INT UNSIGNED',
					'metric'     => 'INT UNSIGNED',
					'statute'    => 'INT UNSIGNED',
				),
			),
		),

		258 => array(
			'mesg_name'   => 'dive_settings',
			'field_defns' => array(
				0   => array(
					'field_name' => 'name',
					'scale'      => 1,
					'offset'     => 0,
					'units'      => '',
					'raw'        => 'VARCHAR(16)',
					'metric'     => 'VARCHAR(16)',
					'statute'    => 'VARCHAR(16)',
				),
				1   => array(
					'field_name' => 'model',
					'scale'      => 1,
					'offset'     => 0,
					'units'      => '',
					'raw'        => 'SMALLINT UNSIGNED',
					'metric'     => 'SMALLINT UNSIGNED',
					'statute'    => 'SMALLINT UNSIGNED',
				),
				2   => array(
					'field_name' => 'gf_low',
					'scale'      => 1,
					'offset'     => 0,
					'units'      => 'percent',
					'raw'        => 'TINYINT UNSIGNED',
					'metric'     => 'TINYINT UNSIGNED',
					'statute'    => 'TINYINT UNSIGNED',
				),
				3   => array(
					'field_name' => 'gf_high',
					'scale'      => 1,
					'offset'     => 0,
					'units'      => 'percent',
					'raw'        => 'TINYINT UNSIGNED',
					'metric'     => 'TINYINT UNSIGNED',
					'statute'    => 'TINYINT UNSIGNED',
				),
				4   => array(
					'field_name' => 'water_type',
					'scale'      => 1,
					'offset'     => 0,
					'units'      => '',
					'raw'        => 'TINYINT UNSIGNED',
					'metric'     => 'TINYINT UNSIGNED',
					'statute'    => 'TINYINT UNSIGNED',
				),
				5   => array(
					'field_name' => 'water_density',
					'scale'      => 1,
					'offset'     => 0,
					'units'      => 'kg/m^3',
					'raw'        => 'FLOAT',
					'metric'     => 'FLOAT',
					'statute'    => 'FLOAT',
				),
				6   => array(
					'field_name' => 'po2_warn',
					'scale'      => 100,
					'offset'     => 0,
					'units'      => 'percent',
					'raw'        => 'DECIMAL(3,2)',
					'metric'     => 'DECIMAL(3,2)',
					'statute'    => 'DECIMAL(3,2)',
				),
				7   => array(
					'field_name' => 'po2_critical',
					'scale'      => 100,
					'offset'     => 0,
					'units'      => 'percent',
					'raw'        => 'DECIMAL(3,2)',
					'metric'     => 'DECIMAL(3,2)',
					'statute'    => 'DECIMAL(3,2)',
				),
				8   => array(
					'field_name' => 'po2_deco',
					'scale'      => 100,
					'offset'     => 0,
					'units'      => 'percent',
					'raw'        => 'DECIMAL(3,2)',
					'metric'     => 'DECIMAL(3,2)',
					'statute'    => 'DECIMAL(3,2)',
				),
				9   => array(
					'field_name' => 'safety_stop_enabled',
					'scale'      => 1,
					'offset'     => 0,
					'units'      => '',
					'raw'        => 'TINYINT(1)',
					'metric'     => 'TINYINT(1)',
					'statute'    => 'TINYINT(1)',
				),
				10  => array(
					'field_name' => 'bottom_depth',
					'scale'      => 1,
					'offset'     => 0,
					'units'      => '',
					'raw'        => 'FLOAT',
					'metric'     => 'FLOAT',
					'statute'    => 'FLOAT',
				),
				11  => array(
					'field_name' => 'bottom_time',
					'scale'      => 1,
					'offset'     => 0,
					'units'      => '',
					'raw'        => 'INT UNSIGNED',
					'metric'     => 'INT UNSIGNED',
					'statute'    => 'INT UNSIGNED',
				),
				12  => array(
					'field_name' => 'apnea_countdown_enabled',
					'scale'      => 1,
					'offset'     => 0,
					'units'      => '',
					'raw'        => 'TINYINT(1)',
					'metric'     => 'TINYINT(1)',
					'statute'    => 'TINYINT(1)',
				),
				13  => array(
					'field_name' => 'apnea_countdown_time',
					'scale'      => 1,
					'offset'     => 0,
					'units'      => '',
					'raw'        => 'INT UNSIGNED',
					'metric'     => 'INT UNSIGNED',
					'statute'    => 'INT UNSIGNED',
				),
				14  => array(
					'field_name' => 'backlight_mode',
					'scale'      => 1,
					'offset'     => 0,
					'units'      => '',
					'raw'        => 'TINYINT UNSIGNED',
					'metric'     => 'TINYINT UNSIGNED',
					'statute'    => 'TINYINT UNSIGNED',
				),
				15  => array(
					'field_name' => 'backlight_brightness',
					'scale'      => 1,
					'offset'     => 0,
					'units'      => '',
					'raw'        => 'TINYINT UNSIGNED',
					'metric'     => 'TINYINT UNSIGNED',
					'statute'    => 'TINYINT UNSIGNED',
				),
				16  => array(
					'field_name' => 'backlight_timeout',
					'scale'      => 1,
					'offset'     => 0,
					'units'      => '',
					'raw'        => 'TINYINT UNSIGNED',
					'metric'     => 'TINYINT UNSIGNED',
					'statute'    => 'TINYINT UNSIGNED',
				),
				17  => array(
					'field_name' => 'repeat_dive_interval',
					'scale'      => 1,
					'offset'     => 0,
					'units'      => 's',
					'raw'        => 'SMALLINT UNSIGNED',
					'metric'     => 'SMALLINT UNSIGNED',
					'statute'    => 'SMALLINT UNSIGNED',
				),
				18  => array(
					'field_name' => 'safety_stop_time',
					'scale'      => 1,
					'offset'     => 0,
					'units'      => 's',
					'raw'        => 'SMALLINT UNSIGNED',
					'metric'     => 'SMALLINT UNSIGNED',
					'statute'    => 'SMALLINT UNSIGNED',
				),
				19  => array(
					'field_name' => 'heart_rate_source_type',
					'scale'      => 1,
					'offset'     => 0,
					'units'      => '',
					'raw'        => 'TINYINT UNSIGNED',
					'metric'     => 'TINYINT UNSIGNED',
					'statute'    => 'TINYINT UNSIGNED',
				),
				20  => array(
					'field_name' => 'heart_rate_source',
					'scale'      => 1,
					'offset'     => 0,
					'units'      => '',
					'raw'        => 'TINYINT UNSIGNED',
					'metric'     => 'TINYINT UNSIGNED',
					'statute'    => 'TINYINT UNSIGNED',
				),
				253 => array(
					'field_name' => 'timestamp',
					'scale'      => 1,
					'offset'     => 0,
					'units'      => 's',
					'raw'        => 'INT UNSIGNED',
					'metric'     => 'INT UNSIGNED',
					'statute'    => 'INT UNSIGNED',
				),
				254 => array(
					'field_name' => 'message_index',
					'scale'      => 1,
					'offset'     => 0,
					'units'      => '',
					'raw'        => 'SMALLINT UNSIGNED',
					'metric'     => 'SMALLINT UNSIGNED',
					'statute'    => 'SMALLINT UNSIGNED',
				),
			),
		),

		259 => array(
			'mesg_name'   => 'dive_gas',
			'field_defns' => array(
				0   => array(
					'field_name' => 'helium_content',
					'scale'      => 1,
					'offset'     => 0,
					'units'      => 'percent',
					'raw'        => 'TINYINT UNSIGNED',
					'metric'     => 'TINYINT UNSIGNED',
					'statute'    => 'TINYINT UNSIGNED',
				),
				1   => array(
					'field_name' => 'oxygen_content',
					'scale'      => 1,
					'offset'     => 0,
					'units'      => 'percent',
					'raw'        => 'TINYINT UNSIGNED',
					'metric'     => 'TINYINT UNSIGNED',
					'statute'    => 'TINYINT UNSIGNED',
				),
				2   => array(
					'field_name' => 'dive_gas_status',
					'scale'      => 1,
					'offset'     => 0,
					'units'      => '',
					'raw'        => 'TINYINT UNSIGNED',
					'metric'     => 'TINYINT UNSIGNED',
					'statute'    => 'TINYINT UNSIGNED',
				),
				3   => array(
					'field_name' => 'dive_gas_mode',
					'scale'      => 1,
					'offset'     => 0,
					'units'      => '',
					'raw'        => 'TINYINT UNSIGNED',
					'metric'     => 'TINYINT UNSIGNED',
					'statute'    => 'TINYINT UNSIGNED',
				),
				254 => array(
					'field_name' => 'message_index',
					'scale'      => 1,
					'offset'     => 0,
					'units'      => '',
					'raw'        => 'SMALLINT UNSIGNED',
					'metric'     => 'SMALLINT UNSIGNED',
					'statute'    => 'SMALLINT UNSIGNED',
				),
			),
		),

		262 => array(
			'mesg_name'   => 'dive_alarm',
			'field_defns' => array(
				0   => array(
					'field_name' => 'depth',
					'scale'      => 1000,
					'offset'     => 0,
					'units'      => 'm',
					'raw'        => 'DECIMAL(10,3)',
					'metric'     => 'DECIMAL(10,3)',
					'statute'    => 'DECIMAL(10,3)',
				),
				1   => array(
					'field_name' => 'time',
					'scale'      => 1,
					'offset'     => 0,
					'units'      => 's',
					'raw'        => 'INT',
					'metric'     => 'INT',
					'statute'    => 'INT',
				),
				2   => array(
					'field_name' => 'enabled',
					'scale'      => 1,
					'offset'     => 0,
					'units'      => '',
					'raw'        => 'TINYINT(1)',
					'metric'     => 'TINYINT(1)',
					'statute'    => 'TINYINT(1)',
				),
				3   => array(
					'field_name' => 'alarm_type',
					'scale'      => 1,
					'offset'     => 0,
					'units'      => '',
					'raw'        => 'TINYINT UNSIGNED',
					'metric'     => 'TINYINT UNSIGNED',
					'statute'    => 'TINYINT UNSIGNED',
				),
				4   => array(
					'field_name' => 'sound',
					'scale'      => 1,
					'offset'     => 0,
					'units'      => '',
					'raw'        => 'TINYINT UNSIGNED',
					'metric'     => 'TINYINT UNSIGNED',
					'statute'    => 'TINYINT UNSIGNED',
				),
				254 => array(
					'field_name' => 'message_index',
					'scale'      => 1,
					'offset'     => 0,
					'units'      => '',
					'raw'        => 'SMALLINT UNSIGNED',
					'metric'     => 'SMALLINT UNSIGNED',
					'statute'    => 'SMALLINT UNSIGNED',
				),
			),
		),

		268 => array(
			'mesg_name'   => 'dive_summary',
			'field_defns' => array(
				0   => array(
					'field_name' => 'reference_mesg',
					'scale'      => 1,
					'offset'     => 0,
					'units'      => '',
					'raw'        => 'SMALLINT UNSIGNED',
					'metric'     => 'SMALLINT UNSIGNED',
					'statute'    => 'SMALLINT UNSIGNED',
				),
				1   => array(
					'field_name' => 'reference_index',
					'scale'      => 1,
					'offset'     => 0,
					'units'      => '',
					'raw'        => 'SMALLINT UNSIGNED',
					'metric'     => 'SMALLINT UNSIGNED',
					'statute'    => 'SMALLINT UNSIGNED',
				),
				2   => array(
					'field_name' => 'avg_depth',
					'scale'      => 1000,
					'offset'     => 0,
					'units'      => 'm',
					'raw'        => 'DECIMAL(10,3)',
					'metric'     => 'DECIMAL(10,3)',
					'statute'    => 'DECIMAL(10,3)',
				),
				3   => array(
					'field_name' => 'max_depth',
					'scale'      => 1000,
					'offset'     => 0,
					'units'      => 'm',
					'raw'        => 'DECIMAL(10,3)',
					'metric'     => 'DECIMAL(10,3)',
					'statute'    => 'DECIMAL(10,3)',
				),
				4   => array(
					'field_name' => 'surface_interval',
					'scale'      => 1,
					'offset'     => 0,
					'units'      => 's',
					'raw'        => 'INT UNSIGNED',
					'metric'     => 'INT UNSIGNED',
					'statute'    => 'INT UNSIGNED',
				),
				5   => array(
					'field_name' => 'start_cns',
					'scale'      => 1,
					'offset'     => 0,
					'units'      => 'percent',
					'raw'        => 'TINYINT UNSIGNED',
					'metric'     => 'TINYINT UNSIGNED',
					'statute'    => 'TINYINT UNSIGNED',
				),
				6   => array(
					'field_name' => 'end_cns',
					'scale'      => 1,
					'offset'     => 0,
					'units'      => 'percent',
					'raw'        => 'TINYINT UNSIGNED',
					'metric'     => 'TINYINT UNSIGNED',
					'statute'    => 'TINYINT UNSIGNED',
				),
				7   => array(
					'field_name' => 'start_n2',
					'scale'      => 1,
					'offset'     => 0,
					'units'      => 'percent',
					'raw'        => 'SMALLINT UNSIGNED',
					'metric'     => 'SMALLINT UNSIGNED',
					'statute'    => 'SMALLINT UNSIGNED',
				),
				8   => array(
					'field_name' => 'end_n2',
					'scale'      => 1,
					'offset'     => 0,
					'units'      => 'percent',
					'raw'        => 'SMALLINT UNSIGNED',
					'metric'     => 'SMALLINT UNSIGNED',
					'statute'    => 'SMALLINT UNSIGNED',
				),
				9   => array(
					'field_name' => 'o2_toxicity',
					'scale'      => 1,
					'offset'     => 0,
					'units'      => 'OTUs',
					'raw'        => 'SMALLINT UNSIGNED',
					'metric'     => 'SMALLINT UNSIGNED',
					'statute'    => 'SMALLINT UNSIGNED',
				),
				10  => array(
					'field_name' => 'dive_number',
					'scale'      => 1,
					'offset'     => 0,
					'units'      => '',
					'raw'        => 'INT UNSIGNED',
					'metric'     => 'INT UNSIGNED',
					'statute'    => 'INT UNSIGNED',
				),
				11  => array(
					'field_name' => 'bottom_time',
					'scale'      => 1000,
					'offset'     => 0,
					'units'      => 's',
					'raw'        => 'DECIMAL(10,3)',
					'metric'     => 'DECIMAL(10,3)',
					'statute'    => 'DECIMAL(10,3)',
				),
				253 => array(
					'field_name' => 'timestamp',
					'scale'      => 1,
					'offset'     => 0,
					'units'      => 's',
					'raw'        => 'INT UNSIGNED',
					'metric'     => 'INT UNSIGNED',
					'statute'    => 'INT UNSIGNED',
				),
			),
		),
	);

	private $data_mesg_info_original; // Original data message info for reference.

	/**
	 * Constructor for phpFITFileAnalysis.
	 *
	 * @param string|array           $file_path_or_data  Path to FIT file or the data itself.
	 * @param array                  $options            Options for processing the FIT file.
	 * @param callable               $record_callback    Callback function for processing record messages.
	 * @param Monolog\Logger         $logger             Logger for debugging.
	 * @param CCM_GPS_Fit_File_Queue $queue              Queue for processing FIT file data.
	 *   Queue class must implement the following methods:
	 *     - get_lock_expiration();
	 *     - lock_process( $reset_start_time = true );
	 *     - get_lock_time();
	 *     - maybe_set_lock_expiration();
	 */
	public function __construct( $file_path_or_data, $options = null, $record_callback = null, $logger = null, $queue = null ) {
		require_once 'class-pffa-data-mesgs.php';
		require_once 'class-pffa-table-cache.php';

		if ( null === $logger) {
			$this->configure_logger();
		} else {
			$this->logger = $logger;
		}

		$this->data_mesg_info_original = $this->data_mesg_info; // Store original data message info for reference.

		if ( isset( $options['input_is_data'] ) ) {
			$this->file_contents = $file_path_or_data;
		} elseif ( isset( $options['buffer_input_to_db'] ) && $options['buffer_input_to_db'] && $this->checkFileBufferOptions( $options['database'] ) ) {
			$this->db_name = $options['database']['data_source_name'];
			$this->db_user = $options['database']['username'];
			$this->db_pass = $options['database']['password'];

			$this->file_buff  = true;
			$this->data_table = $this->cleanTableName( $options['database']['table_name'] ) . '_';

			if ( ! $this->connect_to_db() ) {
				$this->logger->error( 'phpFITFileAnalysis->__construct(): unable to connect to database!' );
				throw new \Exception( 'phpFITFileAnalysis: unable to connect to database' );
			} else {
				$this->logger->debug( 'phpFITFileAnalysis->__construct(): connected to database: ' . $this->db_name );
			}
		} else {
			// $this->logger->debug( 'phpFITFileAnalysis->__construct(): working on: ' . $file_path_or_data );
		}

		if ( ! isset( $options['input_is_data'] ) ) {
			if ( empty( $file_path_or_data ) ) {
				throw new \Exception( 'phpFITFileAnalysis->__construct(): file_path is empty!' );
			}
			if ( ! file_exists( $file_path_or_data ) ) {
				throw new \Exception( 'phpFITFileAnalysis->__construct(): file \'' . $file_path_or_data . '\' does not exist!' );
			}
			$handle = fopen( $file_path_or_data, 'rb' );
			if ( ! $handle ) {
				throw new \Exception( 'phpFITFileAnalysis->__construct(): unable to open file \'' . $file_path_or_data . '\'!' );
			}
			/**
			 * D00001275 Flexible & Interoperable Data Transfer (FIT) Protocol Rev 1.7.pdf
			 * 3.3 FIT File Structure
			 * Header . Data Records . CRC
			 */
			$this->file_contents = $handle;
		}

		// Limit data to contents of $options['limit_data'].
		// In the form of mesg_name => array( allowed field names ).
		// Example: array( 'record' => array( 'position_lat', 'position_long', 'altitude', 'distance', 'speed' ) )
		// Timestamp is always included.
		if ( isset( $options['limit_data'] ) ) {
			$this->limit_data( $options['limit_data'] );
			// $this->logger->debug( 'phpFITFileAnalysis->__construct(): limiting data to ' . print_r( $this->data_mesg_info, true ) );
		}

		$this->options = $options;
		if ( isset( $options['garmin_timestamps'] ) && $options['garmin_timestamps'] == true ) {
			$this->garmin_timestamps = true;
		}
		$this->options['overwrite_with_dev_data'] = false;
		if ( isset( $this->options['overwrite_with_dev_data'] ) && $this->options['overwrite_with_dev_data'] == true ) {
			$this->options['overwrite_with_dev_data'] = true;
		}
		$this->php_trader_ext_loaded = extension_loaded( 'trader' );

		// Process the file contents.
		$this->readHeader();

		$this->logger->debug( 'phpFITFileAnalysis->__construct(): readHeader() completed for ' . $file_path_or_data );

		$this->readDataRecords( $queue );

		$this->logger->debug( 'phpFITFileAnalysis->__construct(): readDataRecords() completed for ' . $file_path_or_data );

		if ( $record_callback ) {
			$this->calculateStopPoints( $record_callback, $queue );
			$this->logger->debug( 'phpFITFileAnalysis->__construct(): calculateStopPoints() completed for ' . $file_path_or_data );
		}

		if ( $this->file_buff ) {
			$this->data_mesgs = new \PFFA_Data_Mesgs( $this->db, $this->tables_created, $this->logger );
		} else {
			$this->oneElementArrays();
			$this->processHrMessages( $queue );

			// $this->logger->debug( 'phpFITFileAnalysis->__construct(): processHrMessages() completed for ' . $file_path_or_data );

			// Handle options.
			$this->fixData( $this->options, $queue );

			// $this->logger->debug( 'phpFITFileAnalysis->__construct(): fixData() completed for ' . $file_path_or_data );

			$this->setUnits( $this->options, $queue );

			// $this->logger->debug( 'phpFITFileAnalysis->__construct(): setUnits() completed for ' . $file_path_or_data );

		}

		$this->logger->debug( 'phpFITFileAnalysis->__construct(): complete for ' . $file_path_or_data );

		// $this->logger->debug( 'defn_mesgs: ' . print_r( $this->defn_mesgs, true ) );
		// $this->logger->debug( 'defn_mesgs_all: ' . print_r( $this->defn_mesgs_all, true ) );

		fclose( $this->file_contents );
	}

	/**
	 * Add another fit file to the data.
	 *
	 * @param string $file_path Path to the FIT file.
	 * @param string $queue     Queue for processing FIT file data.
	 */
	public function addFile( $file_path, $queue = null ) {
		if ( isset( $this->options['buffer_input_to_db'] ) && $this->options['buffer_input_to_db'] && $this->checkFileBufferOptions( $this->options['database'] ) ) {
			if ( ! $this->connect_to_db() ) {
				$this->logger->error( 'phpFITFileAnalysis->addFile(): unable to connect to database!' );
				throw new \Exception( 'phpFITFileAnalysis: unable to connect to database' );
			} else {
				$this->logger->debug( 'phpFITFileAnalysis->addFile(): connected to database: ' . $this->db_name );
			}
		}

		if ( ! isset( $options['input_is_data'] ) ) {
			if ( empty( $file_path ) ) {
				throw new \Exception( 'phpFITFileAnalysis->addFile(): file_path is empty!' );
			}
			if ( ! file_exists( $file_path ) ) {
				throw new \Exception( 'phpFITFileAnalysis->addFile(): file \'' . $file_path_or_data . '\' does not exist!' );
			}
			$handle = fopen( $file_path, 'rb' );
			if ( ! $handle ) {
				throw new \Exception( 'phpFITFileAnalysis->addFile(): unable to open file \'' . $file_path_or_data . '\'!' );
			}

			$this->file_contents = $handle;
		}

		$this->readHeader();
		$this->logger->debug( 'phpFITFileAnalysis->addFile(): readHeader() completed for ' . $file_path );

		$this->readDataRecords( $queue );
		$this->logger->debug( 'phpFITFileAnalysis->addFile(): readDataRecords() completed for ' . $file_path );

		if ( $this->file_buff ) {
			$this->data_mesgs->setTables( $this->tables_created );
		} else {
			throw new \Exception( 'phpFITFileAnalysis->addFile(): you can\'t add a file unless data is stored in tables' );
		}

		$this->logger->debug( 'phpFITFileAnalysis->addFile(): complete for ' . $file_path );

		fclose( $this->file_contents );
	}

	/**
	 * Establish database connection.
	 */
	private function connect_to_db() {
		if ( $this->file_buff ) {
			try {
				$this->db = new \PDO( $this->db_name, $this->db_user, $this->db_pass );
				$this->db->setAttribute( \PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION ); // Enable exceptions for errors
				// $this->logger->debug( 'phpFITFileAnalysis: connected to database - after attributes: ' . print_r( $this->db, true ) );
			} catch ( \PDOException $e ) {
				$this->logger->error( 'Connection failed: ' . $e->getMessage() );
				return false;
			}
		}
		return true;
	}

	/**
	 * Delete all related tables.
	 */
	public function drop_tables() {
		if ( $this->file_buff ) {
			try {
				foreach ( $this->tables_created as $table ) {
					$table_name = $this->cleanTableName( $table['location'] );
					$this->logger->debug( 'phpFITFileAnalysis: dropping table ' . $table_name );
					$this->db->exec( 'DROP TABLE IF EXISTS ' . $table_name );
				}
				$this->db = null; // Closing the PDO connection by setting it to null
			} catch ( \PDOException $e ) {
				$this->logger->error( 'phpFITFileAnalysis: Error dropping tables: ' . $e->getMessage() );
			}
		}
		$this->db = null; // Closing the PDO connection by setting it to null
	}

	/**
	 * Get table information.
	 *
	 * @return array
	 */
	public function getTableInfo() {
		return $this->tables_created;
	}

	/**
	 * Check validity of file buffer and database options.
	 *
	 * @param array $options expects:
	 *      'table_name'       => 'event_101',
	 *      'data_source_name' => 'mysql:host=localhost;dbname=testdb',
	 *      'username'         => 'user',
	 *      'password'         => 'password',
	 * @return bool
	 * @throws \Exception if any of the required options are missing or invalid.
	 */
	private function checkFileBufferOptions( $options ) {

		if ( ! isset( $options['table_name'] ) || ! is_string( $options['table_name'] ) ) {
			throw new \Exception( 'phpFITFileAnalysis->checkFileBufferOptions(): table_name option is required when buffer_input_to_db is set to true!' );
		}

		if ( ! isset( $options['data_source_name'] ) || ! is_string( $options['data_source_name'] ) ) {
			throw new \Exception( 'phpFITFileAnalysis->checkFileBufferOptions(): data_source_name option is required when buffer_input_to_db is set to true!' );
		}

		if ( ! isset( $options['username'] ) || ! is_string( $options['username'] ) ) {
			throw new \Exception( 'phpFITFileAnalysis->checkFileBufferOptions(): username option is required when buffer_input_to_db is set to true!' );
		}
		if ( ! isset( $options['password'] ) || ! is_string( $options['password'] ) ) {
			throw new \Exception( 'phpFITFileAnalysis->checkFileBufferOptions(): password option is required when buffer_input_to_db is set to true!' );
		}

		return true;
	}

	/**
	 * Clean table name to be used in SQL queries.
	 *
	 * @param string $table_name
	 * @return string
	 */
	private function cleanTableName( $table_name ) {
		$table_name = str_replace( ' ', '_', $table_name );
		$table_name = str_replace( '-', '_', $table_name );
		$table_name = str_replace( '.', '_', $table_name );
		$table_name = str_replace( '/', '_', $table_name );
		$table_name = str_replace( '\\', '_', $table_name );
		$table_name = str_replace( ':', '_', $table_name );
		$table_name = str_replace( '(', '_', $table_name );
		$table_name = str_replace( ')', '_', $table_name );
		$table_name = str_replace( '\'', '_', $table_name );
		$table_name = str_replace( '"', '_', $table_name );
		$table_name = str_replace( '!', '_', $table_name );
		$table_name = str_replace( '?', '_', $table_name );
		$table_name = str_replace( ' ', '_', $table_name );
		$table_name = str_replace( '=', '_', $table_name );
		$table_name = str_replace( '~', '_', $table_name );
		$table_name = str_replace( '`', '_', $table_name );
		$table_name = str_replace( '^', '_', $table_name );
		$table_name = str_replace( '&', '_', $table_name );
		$table_name = str_replace( '+', '_', $table_name );
		$table_name = str_replace( ';', '_', $table_name );
		$table_name = str_replace( '>', '_', $table_name );
		$table_name = str_replace( '<', '_', $table_name );

		return $table_name;
	}

	/**
	 * Modify the data_mesg_info array to only include the fields specified in
	 * options.
	 *
	 * @param array $options
	 * @return void
	 */
	private function limit_data( $options ) {
		if ( ! is_array( $options ) ) {
			throw new \Exception( 'phpFITFileAnalysis->limit_data(): options must be an array!' );
		}

		// ensure $options contains the mandatory fields: 'field_description' and 'developer_data_id'.
		if ( ! isset( $options['field_description'] ) ) {
			$options['field_description'] = array();
		}
		if ( ! isset( $options['developer_data_id'] ) ) {
			$options['developer_data_id'] = array();
		}

		// $this->logger->debug( 'phpFITFileAnalysis->limit_data(): limiting data to ' . print_r( $options, true ) );

		foreach ( $this->data_mesg_info as $mesg_num => $mesg_info ) {
			if ( isset( $options[ $mesg_info['mesg_name'] ] ) && is_array( $options[ $mesg_info['mesg_name'] ] ) && count( $options[ $mesg_info['mesg_name'] ] ) > 0 ) {
				foreach ( $this->data_mesg_info[ $mesg_num ]['field_defns'] as $field_num => $field_defn ) {
					if ( ! in_array( $field_defn['field_name'], $options[ $mesg_info['mesg_name'] ], true ) && 'timestamp' !== $field_defn['field_name'] ) {
						unset( $this->data_mesg_info[ $mesg_num ]['field_defns'][ $field_num ] );
					}
				}
			} elseif ( ! isset( $options[ $mesg_info['mesg_name'] ] ) ) {
				// If no options are provided, remove all fields.
				// $this->logger->debug( 'phpFITFileAnalysis->limit_data(): removing all fields for ' . $mesg_info['mesg_name'] );
				unset( $this->data_mesg_info[ $mesg_num ] );
			}
		}
	}

	/**
	 * D00001275 Flexible & Interoperable Data Transfer (FIT) Protocol Rev 1.7.pdf
	 * Table 3-1. Byte Description of File Header
	 */
	private function readHeader() {
		$header_size = unpack( 'C1header_size', fread( $this->file_contents, 1 ) )['header_size'];
		++$this->file_pointer;

		if ( $header_size != 12 && $header_size != 14 ) {
			throw new \Exception( 'phpFITFileAnalysis->readHeader(): not a valid header size!' );
		}

		$header_fields = 'C1protocol_version/' .
			'v1profile_version/' .
			'V1data_size/' .
			'C4data_type';
		if ( $header_size > 12 ) {
			$header_fields .= '/v1crc';
		}

		$this->file_header                = unpack( $header_fields, fread( $this->file_contents, $header_size - 1 ) );
		$this->file_header['header_size'] = $header_size;

		$this->file_pointer += $this->file_header['header_size'] - 1;

		$file_extension = sprintf( '%c%c%c%c', $this->file_header['data_type1'], $this->file_header['data_type2'], $this->file_header['data_type3'], $this->file_header['data_type4'] );

		if ( $file_extension != '.FIT' || $this->file_header['data_size'] <= 0 ) {
			throw new \Exception( 'phpFITFileAnalysis->readHeader(): not a valid FIT file!' );
		}

		// JKK. Original content was commented out.
		// if (strlen($this->file_contents) - $header_size - 2 !== $this->file_header['data_size']) {
			// Overwrite the data_size. Seems to be incorrect if there are buffered messages e.g. HR records.
			// $this->file_header['data_size'] = $this->file_header['crc'] - $header_size + 2;
		// }
	}

	/**
	 * Reads the remainder of $this->file_contents and store the data in the $this->data_mesgs array.
	 *
	 * @param CCM_GPS_Fit_File_Queue|null $queue           Queue for processing FIT file data.
	 */
	private function readDataRecords( $queue = null ) {
		$record_header_byte  = 0;
		$message_type        = 0;
		$developer_data_flag = 0;
		$local_mesg_type     = 0;
		$previousTS          = 0;
		$record_count        = 0;
		// $last_definition_num = 0;
		// $first_data_record   = 0;

		while ( $this->file_header['header_size'] + $this->file_header['data_size'] > $this->file_pointer ) {
			// Check if we need to re-lock the process
			$this->maybe_set_lock_expiration( $queue );

			++$record_count;

			if ($record_count % 50000 === 0) {
				$this->logger->debug( 'readDataRecords: Processed ' . number_format( $record_count ) . ' records from the fit file so far' );
			}

			// if ($record_count % 1000 === 0) {
			//  $this->logger->debug( 'phpFITFileAnalysis->readDataRecords(): record count: ' . $record_count );
			//  $this->logger->debug( 'Memory usage: ' . $this->formatMemoryUsage( memory_get_usage( true ) ) );
			// }

			$record_header_byte = unpack( 'C1record_header_byte', fread( $this->file_contents, 1 ) )['record_header_byte'];
			++$this->file_pointer;

			$compressedTimestamp = false;
			$tsOffset            = 0;
			/**
			 * D00001275 Flexible & Interoperable Data Transfer (FIT) Protocol Rev 2.2.pdf
			 * Table 4-1. Normal Header Bit Field Description
			 */
			if ( ( $record_header_byte >> 7 ) & 1 ) {  // Check that it's a normal header
				// Header with compressed timestamp
				$message_type        = 0;  // always 0: DATA_MESSAGE
				$developer_data_flag = 0;  // always 0: DATA_MESSAGE
				$local_mesg_type     = ( $record_header_byte >> 5 ) & 3;  // bindec('0011') == 3
				$tsOffset            = $record_header_byte & 31;
				$compressedTimestamp = true;
			} else {
				// Normal header
				$message_type        = ( $record_header_byte >> 6 ) & 1;  // 1: DEFINITION_MESSAGE; 0: DATA_MESSAGE
				$developer_data_flag = ( $record_header_byte >> 5 ) & 1;  // 1: DEFINITION_MESSAGE; 0: DATA_MESSAGE
				$local_mesg_type     = $record_header_byte & 15;  // bindec('1111') == 15
			}

			switch ( $message_type ) {
				case DEFINITION_MESSAGE:
					// $last_definition_num = $record_count;
					// if ( $first_data_record > 0 && $last_definition_num > $first_data_record ) {
					//  $this->logger->debug( 'phpFITFileAnalysis->readDataRecords(): definition message after data record!' );
					//  $this->logger->debug( 'phpFITFileAnalysis->readDataRecords(): record count: ' . $record_count );
					// }
					/**
					 * D00001275 Flexible & Interoperable Data Transfer (FIT) Protocol Rev 1.7.pdf
					 * Table 4-1. Normal Header Bit Field Description
					 */
					fseek( $this->file_contents, 1, SEEK_CUR );  // Reserved - IGNORED
					++$this->file_pointer;  // Reserved - IGNORED
					$architecture = ord( fread( $this->file_contents, 1 ) );  // Architecture
					// $architecture = ord(substr($this->file_contents, $this->file_pointer, 1));  // Architecture
					++$this->file_pointer;

					$this->types = $this->endianness[ $architecture ] ?? array();

					$global_mesg_num = ( 0 === $architecture ) ? unpack( 'v1tmp', fread( $this->file_contents, 2 ) )['tmp'] : unpack( 'n1tmp', fread( $this->file_contents, 2 ) )['tmp'];
					// $global_mesg_num = ($architecture === 0) ? unpack('v1tmp', substr($this->file_contents, $this->file_pointer, 2))['tmp'] : unpack('n1tmp', substr($this->file_contents, $this->file_pointer, 2))['tmp'];
					$this->file_pointer += 2;

					$num_fields = ord( fread( $this->file_contents, 1 ) );
					// $num_fields = ord(substr($this->file_contents, $this->file_pointer, 1));
					++$this->file_pointer;

					$field_definitions = array();
					$total_size        = 0;
					for ( $i = 0; $i < $num_fields; ++$i ) {
						$field_definition_number = ord( fread( $this->file_contents, 1 ) );
						// $field_definition_number = ord(substr($this->file_contents, $this->file_pointer, 1));
						++$this->file_pointer;
						$size = ord( fread( $this->file_contents, 1 ) );
						// $size = ord( substr( $this->file_contents, $this->file_pointer, 1 ) );
						++$this->file_pointer;
						$base_type = ord( fread( $this->file_contents, 1 ) );
						// $base_type = ord( substr( $this->file_contents, $this->file_pointer, 1 ) );
						++$this->file_pointer;

						$field_definitions[] = array(
							'field_definition_number' => $field_definition_number,
							'size'                    => $size,
							'base_type'               => $base_type,
						);
						$total_size         += $size;
					}

					$num_dev_fields        = 0;
					$dev_field_definitions = array();
					if ( $developer_data_flag === 1 ) {
						$num_dev_fields = ord( fread( $this->file_contents, 1 ) );
						// $num_dev_fields = ord( substr( $this->file_contents, $this->file_pointer, 1 ) );
						++$this->file_pointer;

						for ( $i = 0; $i < $num_dev_fields; ++$i ) {
							// $field_definition_number = ord( substr( $this->file_contents, $this->file_pointer, 1 ) );
							$field_definition_number = ord( fread( $this->file_contents, 1 ) );
							++$this->file_pointer;
							// $size = ord( substr( $this->file_contents, $this->file_pointer, 1 ) );
							$size = ord( fread( $this->file_contents, 1 ) );
							++$this->file_pointer;
							// $developer_data_index = ord( substr( $this->file_contents, $this->file_pointer, 1 ) );
							$developer_data_index = ord( fread( $this->file_contents, 1 ) );
							++$this->file_pointer;

							$dev_field_definitions[] = array(
								'field_definition_number' => $field_definition_number,
								'size'                    => $size,
								'developer_data_index'    => $developer_data_index,
							);
							$total_size             += $size;
						}
					}

					$this->defn_mesgs[ $local_mesg_type ] = array(
						'global_mesg_num'       => $global_mesg_num,
						'num_fields'            => $num_fields,
						'field_defns'           => $field_definitions,
						'num_dev_fields'        => $num_dev_fields,
						'dev_field_definitions' => $dev_field_definitions,
						'total_size'            => $total_size,
					);
					$this->defn_mesgs_all[]               = array(
						'global_mesg_num'       => $global_mesg_num,
						'num_fields'            => $num_fields,
						'field_defns'           => $field_definitions,
						'num_dev_fields'        => $num_dev_fields,
						'dev_field_definitions' => $dev_field_definitions,
						'total_size'            => $total_size,
					);

					// $this->logger->debug( "phpFITFileAnalysis->readDataRecords() - read definition message, $local_mesg_type: " . print_r( $this->defn_mesgs[ $local_mesg_type ], true ) );
					break;

				case DATA_MESSAGE:
					// if ( $first_data_record === 0 ) {
					//  $first_data_record = $record_count;
					// }

					// Check that we have information on the Data Message.
					if ( isset( $this->data_mesg_info[ $this->defn_mesgs[ $local_mesg_type ]['global_mesg_num'] ] ) ) {
						// If table is not build for this message type, build it.
						// Use $this->defn_mesgs[ $local_mesg_type ]['field_defns']
						// to set column names.  How do we think about column type?

						$tmp_record_array = array();  // Temporary array to store Record data message pieces
						$tmp_value        = null;  // Placeholder for value for checking before inserting into the tmp_record_array

						$mesg_name = $this->data_mesg_info[ $this->defn_mesgs[ $local_mesg_type ]['global_mesg_num'] ]['mesg_name'];
						$tmp_mesg  = array( $mesg_name => array() );

						foreach ( $this->defn_mesgs[ $local_mesg_type ]['field_defns'] as $field_defn ) {
							// Check that we have information on the Field Definition and a valid base type exists.
							if ( isset( $this->data_mesg_info[ $this->defn_mesgs[ $local_mesg_type ]['global_mesg_num'] ]['field_defns'][ $field_defn['field_definition_number'] ] ) && isset( $this->types[ $field_defn['base_type'] ] ) ) {
								$field_name = $this->data_mesg_info[ $this->defn_mesgs[ $local_mesg_type ]['global_mesg_num'] ]['field_defns'][ $field_defn['field_definition_number'] ]['field_name'];
								// Check if it's an invalid value for the type
								$tmp_value = unpack( $this->types[ $field_defn['base_type'] ]['format'], fread( $this->file_contents, $field_defn['size'] ) )['tmp'];
								// $tmp_value = unpack( $this->types[ $field_defn['base_type'] ]['format'], substr( $this->file_contents, $this->file_pointer, $field_defn['size'] ) )['tmp'];
								if ( $tmp_value !== $this->invalid_values[ $field_defn['base_type'] ] || $this->defn_mesgs[ $local_mesg_type ]['global_mesg_num'] === 132 ) {
									// If it's a timestamp, compensate between different in FIT and Unix timestamp epochs
									if ( $field_defn['field_definition_number'] === 253 && ! $this->garmin_timestamps ) {
										$tmp_value += FIT_UNIX_TS_DIFF;
									}

									// If it's a Record data message, store all the pieces in the temporary array as the timestamp may not be first...
									if ( $this->defn_mesgs[ $local_mesg_type ]['global_mesg_num'] === 20 ) {
										$tmp_record_array[ $this->data_mesg_info[ $this->defn_mesgs[ $local_mesg_type ]['global_mesg_num'] ]['field_defns'][ $field_defn['field_definition_number'] ]['field_name'] ] = $tmp_value / $this->data_mesg_info[ $this->defn_mesgs[ $local_mesg_type ]['global_mesg_num'] ]['field_defns'][ $field_defn['field_definition_number'] ]['scale'] - $this->data_mesg_info[ $this->defn_mesgs[ $local_mesg_type ]['global_mesg_num'] ]['field_defns'][ $field_defn['field_definition_number'] ]['offset'];
									} elseif ( $this->defn_mesgs[ $local_mesg_type ]['global_mesg_num'] === 206 ) {  // Developer Data
										$tmp_record_array[ $this->data_mesg_info[ $this->defn_mesgs[ $local_mesg_type ]['global_mesg_num'] ]['field_defns'][ $field_defn['field_definition_number'] ]['field_name'] ] = $tmp_value;
									} elseif ( $field_defn['base_type'] === 7 ) {
										// Handle strings appropriately
											// $this->data_mesgs[ $this->data_mesg_info[ $this->defn_mesgs[ $local_mesg_type ]['global_mesg_num'] ]['mesg_name'] ][ $this->data_mesg_info[ $this->defn_mesgs[ $local_mesg_type ]['global_mesg_num'] ]['field_defns'][ $field_defn['field_definition_number'] ]['field_name'] ][] = filter_var( $tmp_value, FILTER_SANITIZE_SPECIAL_CHARS );  // JKK: replaced deprecated FILTER_SANITIZE_STRING.
											$tmp_mesg[ $mesg_name ][ $field_name ][] = filter_var( trim( $tmp_value ), FILTER_SANITIZE_SPECIAL_CHARS );
											// $this->logger->debug( 'Handling string field, ' . $field_name . ': ' . $tmp_value . ' -> ' . filter_var( trim( $tmp_value ), FILTER_SANITIZE_SPECIAL_CHARS ) );
									} else {
										// Handle arrays
										if ( $field_defn['size'] !== $this->types[ $field_defn['base_type'] ]['bytes'] ) {
											$tmp_array = array();
											$num_vals  = $field_defn['size'] / $this->types[ $field_defn['base_type'] ]['bytes'];
											fseek( $this->file_contents, -$field_defn['size'], SEEK_CUR );
											for ( $i = 0; $i < $num_vals; ++$i ) {
												$tmp_array[] = unpack(
													$this->types[ $field_defn['base_type'] ]['format'],
													fread(
														$this->file_contents,
														$this->types[ $field_defn['base_type'] ]['bytes']
													)
												)['tmp'] / $this->data_mesg_info[ $this->defn_mesgs[ $local_mesg_type ]['global_mesg_num'] ]['field_defns'][ $field_defn['field_definition_number'] ]['scale'] - $this->data_mesg_info[ $this->defn_mesgs[ $local_mesg_type ]['global_mesg_num'] ]['field_defns'][ $field_defn['field_definition_number'] ]['offset'];
												// $tmp_array[] = unpack(
												// $this->types[ $field_defn['base_type'] ]['format'],
												// substr(
												// $this->file_contents,
												// $this->file_pointer + ( $i * $this->types[ $field_defn['base_type'] ]['bytes'] ),
												// $field_defn['size']
												// )
												// )['tmp'] / $this->data_mesg_info[ $this->defn_mesgs[ $local_mesg_type ]['global_mesg_num'] ]['field_defns'][ $field_defn['field_definition_number'] ]['scale'] - $this->data_mesg_info[ $this->defn_mesgs[ $local_mesg_type ]['global_mesg_num'] ]['field_defns'][ $field_defn['field_definition_number'] ]['offset'];
											}
											// $this->data_mesgs[ $this->data_mesg_info[ $this->defn_mesgs[ $local_mesg_type ]['global_mesg_num'] ]['mesg_name'] ][ $this->data_mesg_info[ $this->defn_mesgs[ $local_mesg_type ]['global_mesg_num'] ]['field_defns'][ $field_defn['field_definition_number'] ]['field_name'] ][] = $tmp_array;
											$tmp_mesg[ $mesg_name ][ $field_name ][] = $tmp_array;
											// $this->logger->debug( $this->data_mesg_info[ $this->defn_mesgs[ $local_mesg_type ]['global_mesg_num'] ]['mesg_name'] . '[' . $this->data_mesg_info[ $this->defn_mesgs[ $local_mesg_type ]['global_mesg_num'] ]['field_defns'][ $field_defn['field_definition_number'] ]['field_name'] . ']: ' . json_encode( $tmp_array ) );
										} else {
											// $this->data_mesgs[ $this->data_mesg_info[ $this->defn_mesgs[ $local_mesg_type ]['global_mesg_num'] ]['mesg_name'] ][ $this->data_mesg_info[ $this->defn_mesgs[ $local_mesg_type ]['global_mesg_num'] ]['field_defns'][ $field_defn['field_definition_number'] ]['field_name'] ][] = $tmp_value / $this->data_mesg_info[ $this->defn_mesgs[ $local_mesg_type ]['global_mesg_num'] ]['field_defns'][ $field_defn['field_definition_number'] ]['scale'] - $this->data_mesg_info[ $this->defn_mesgs[ $local_mesg_type ]['global_mesg_num'] ]['field_defns'][ $field_defn['field_definition_number'] ]['offset'];
											$tmp_mesg[ $mesg_name ][ $field_name ][] = $tmp_value / $this->data_mesg_info[ $this->defn_mesgs[ $local_mesg_type ]['global_mesg_num'] ]['field_defns'][ $field_defn['field_definition_number'] ]['scale'] - $this->data_mesg_info[ $this->defn_mesgs[ $local_mesg_type ]['global_mesg_num'] ]['field_defns'][ $field_defn['field_definition_number'] ]['offset'];
											// $this->logger->debug( $this->data_mesg_info[ $this->defn_mesgs[ $local_mesg_type ]['global_mesg_num'] ]['mesg_name'] . '[' . $this->data_mesg_info[ $this->defn_mesgs[ $local_mesg_type ]['global_mesg_num'] ]['field_defns'][ $field_defn['field_definition_number'] ]['field_name'] . ']: ' . ( $tmp_value / $this->data_mesg_info[ $this->defn_mesgs[ $local_mesg_type ]['global_mesg_num'] ]['field_defns'][ $field_defn['field_definition_number'] ]['scale'] - $this->data_mesg_info[ $this->defn_mesgs[ $local_mesg_type ]['global_mesg_num'] ]['field_defns'][ $field_defn['field_definition_number'] ]['offset'] ) );
										}
									}
								} else {
									$file_key       = $this->data_mesg_info[ $this->defn_mesgs[ $local_mesg_type ]['global_mesg_num'] ]['mesg_name'];
									$field_key      = array( $this->data_mesg_info[ $this->defn_mesgs[ $local_mesg_type ]['global_mesg_num'] ]['field_defns'][ $field_defn['field_definition_number'] ]['field_name'] );
									$always_process = array( array( 'avg_heart_rate' ), array( 'max_heart_rate' ), array( 'avg_power' ), array( 'max_power' ), array( 'normalized_power' ), array( 'total_work' ), array( 'total_cycles' ), array( 'avg_cadence' ), array( 'max_cadence' ), array( 'avg_fractional_cadence' ), array( 'max_fractional_cadence' ), array( 'training_stress_score' ), array( 'intensity_factor' ), array( 'threshold_power' ), array( 'time_in_hr_zone' ), array( 'total_training_effect' ), array( 'total_ascent' ), array( 'total_descent' ) );

									if ( $file_key === 'session' && in_array( $field_key, $always_process, true ) ) {
										// $this->data_mesgs[ $this->data_mesg_info[ $this->defn_mesgs[ $local_mesg_type ]['global_mesg_num'] ]['mesg_name'] ][ $this->data_mesg_info[ $this->defn_mesgs[ $local_mesg_type ]['global_mesg_num'] ]['field_defns'][ $field_defn['field_definition_number'] ]['field_name'] ][] = null;
										$tmp_mesg[ $mesg_name ][ $field_name ][] = null;
									}
								}
							} else {
								fseek( $this->file_contents, $field_defn['size'], SEEK_CUR );
								$missing_field = $this->data_mesg_info_original[ $this->defn_mesgs[ $local_mesg_type ]['global_mesg_num'] ]['field_defns'][ $field_defn['field_definition_number'] ]['field_name'] ?? $field_defn['field_definition_number'];
								// $this->logger->debug( "phpFITFileAnalysis->readDataRecords(), $mesg_name - skipping field: " . $missing_field);
							}
							$this->file_pointer += $field_defn['size'];
						}

						// Handle Developer Data
						// JKK.
						// $this->logger->debug( "defn_mesgs[ $local_mesg_type ] : " . print_r( $this->defn_mesgs[ $local_mesg_type], true ) );
						// $this->logger->debug( 'tmp_record_array: ' . print_r( $tmp_record_array, true ) );
						if ( $this->defn_mesgs[ $local_mesg_type ]['global_mesg_num'] === 206 ) {
							$mesg_name               = 'developer_data';
							$developer_data_index    = $tmp_record_array['developer_data_index'];
							$field_definition_number = $tmp_record_array['field_definition_number'];
							unset( $tmp_record_array['developer_data_index'] );
							unset( $tmp_record_array['field_definition_number'] );
							if ( isset( $tmp_record_array['field_name'] ) ) {  // Get rid of special/invalid characters after the null terminated string
								$tmp_record_array['field_name'] = strtolower( implode( '', explode( "\0", $tmp_record_array['field_name'] ) ) );
							}
							if ( isset( $tmp_record_array['units'] ) ) {
								$tmp_record_array['units'] = strtolower( implode( '', explode( "\0", $tmp_record_array['units'] ) ) );
							}
							$this->dev_field_descriptions[ $developer_data_index ][ $field_definition_number ] = $tmp_record_array;
							unset( $tmp_record_array );
						}
						foreach ( $this->defn_mesgs[ $local_mesg_type ]['dev_field_definitions'] as $field_defn ) {
							// JKK.
							// $this->logger->debug( 'phpFITFileAnalysis->readDataRecords() - read developer data field definition: ' . print_r( $field_defn, true ) );
							// $this->logger->debug( '  dev_field_descriptions: ' . print_r( $this->dev_field_descriptions, true ) );

							$field_name = $this->dev_field_descriptions[ $field_defn['developer_data_index'] ][ $field_defn['field_definition_number'] ]['field_name'];

							// Units
							if ( ! isset( $this->data_mesgs['developer_data'] ) ) {
								$this->data_mesgs['developer_data'] = array();
							}

							if ( ! isset( $this->dev_field_descriptions[ $field_defn['developer_data_index'] ] ) ) {
								continue;
							}

							// $this->data_mesgs['developer_data'][ $this->dev_field_descriptions[ $field_defn['developer_data_index'] ][ $field_defn['field_definition_number'] ]['field_name'] ]['units'] = $this->dev_field_descriptions[ $field_defn['developer_data_index'] ][ $field_defn['field_definition_number'] ]['units'] ?? null;

							// Don't store units in the data_mesg array.
							// $tmp_mesg[ $mesg_name ][ $field_name ]['units'] = $this->dev_field_descriptions[ $field_defn['developer_data_index'] ][ $field_defn['field_definition_number'] ]['units'] ?? null;

							// Data
							$tmp_data = unpack( $this->types[ $this->dev_field_descriptions[ $field_defn['developer_data_index'] ][ $field_defn['field_definition_number'] ]['fit_base_type_id'] ]['format'], fread( $this->file_contents, $field_defn['size'] ) )['tmp'];
							// $this->data_mesgs['developer_data'][ $this->dev_field_descriptions[ $field_defn['developer_data_index'] ][ $field_defn['field_definition_number'] ]['field_name'] ]['data'][] = $tmp_data;

							// Just store the data in the data_mesg array.
							// $tmp_mesg[ $mesg_name ][ $field_name ]['data'] = $tmp_data;
							$tmp_mesg[ $mesg_name ][ $field_name ] = $tmp_data;

							// $this->data_mesgs['developer_data'][ $this->dev_field_descriptions[ $field_defn['developer_data_index'] ][ $field_defn['field_definition_number'] ]['field_name'] ]['data'][] = unpack( $this->types[ $this->dev_field_descriptions[ $field_defn['developer_data_index'] ][ $field_defn['field_definition_number'] ]['fit_base_type_id'] ]['format'], substr( $this->file_contents, $this->file_pointer, $field_defn['size'] ) )['tmp'];

							// $this->logger->debug( 'developer_data[' . $field_name . ']: ' . $tmp_data . ' ' . $tmp_mesg[ $mesg_name ][ $field_name ]['units'] );

							$this->file_pointer += $field_defn['size'];
						}

						// Process the temporary array and load values into the public data messages array
						if ( ! empty( $tmp_record_array ) ) {
							$timestamp = isset( $this->data_mesgs['record']['timestamp'] ) ? max( $this->data_mesgs['record']['timestamp'] ) + 1 : 0;
							if ( $compressedTimestamp ) {
								if ( $previousTS === 0 ) {
									// This should not happen! Throw exception?
								} else {
									$previousTS -= FIT_UNIX_TS_DIFF; // back to FIT timestamps epoch
									$fiveLsb     = $previousTS & 0x1F;
									if ( $tsOffset >= $fiveLsb ) {
										// No rollover
										$timestamp = $previousTS - $fiveLsb + $tsOffset;
									} else {
										// Rollover
										$timestamp = $previousTS - $fiveLsb + $tsOffset + 32;
									}
									$timestamp  += FIT_UNIX_TS_DIFF; // back to Unix timestamps epoch
									$previousTS += FIT_UNIX_TS_DIFF;
								}
							} elseif ( isset( $tmp_record_array['timestamp'] ) ) {
								if ( $tmp_record_array['timestamp'] > 0 ) {
									$timestamp  = $tmp_record_array['timestamp'];
									$previousTS = $timestamp;
								}
									unset( $tmp_record_array['timestamp'] );
							}

							// $this->logger->debug( 'record: ' . $timestamp . ' | ' . json_encode( $tmp_record_array ) );

							// $this->data_mesgs['record']['timestamp'][] = $timestamp;
							$tmp_mesg['record']['timestamp'] = $timestamp;

							foreach ( $tmp_record_array as $key => $value ) {
								if ( $value !== null ) {
									// $this->data_mesgs['record'][ $key ][ $timestamp ] = $value;
									$tmp_mesg['record'][ $key ] = $value;
								}
							}
						}

						$this->storeMesg( $tmp_mesg, $local_mesg_type );

					} else {
						fseek( $this->file_contents, $this->defn_mesgs[ $local_mesg_type ]['total_size'], SEEK_CUR );
						$this->file_pointer += $this->defn_mesgs[ $local_mesg_type ]['total_size'];
						$skipped_mesg        = $this->data_mesg_info_original[ $this->defn_mesgs[ $local_mesg_type ]['global_mesg_num'] ]['mesg_name'] ?? $this->defn_mesgs[ $local_mesg_type ]['global_mesg_num'];
						// $this->logger->debug( 'phpFITFileAnalysis->readDataRecords(): skipping message type: ' . $skipped_mesg );
					}
			}
		}  // while loop

		$this->storeMesg( null, null, true );  // Flush any remaining data to the database

		// Overwrite native FIT fields (e.g. Power, HR, Cadence, etc) with developer data by default
		if ( ! empty( $this->dev_field_descriptions ) ) {
			foreach ( $this->dev_field_descriptions as $developer_data_index ) {
				foreach ( $developer_data_index as $field_definition_number ) {
					if ( isset( $field_definition_number['native_field_num'] ) ) {
						if ( isset( $this->data_mesgs['record'][ $field_definition_number['field_name'] ] ) && ! $this->options['overwrite_with_dev_data'] ) {
							continue;
						}

						if ( isset( $this->data_mesgs['developer_data'][ $field_definition_number['field_name'] ]['data'] ) ) {
							$this->data_mesgs['record'][ $field_definition_number['field_name'] ] = $this->data_mesgs['developer_data'][ $field_definition_number['field_name'] ]['data'];
							$tmp_mesg['record'][ $field_definition_number['field_name'] ]         = $this->data_mesgs['developer_data'][ $field_definition_number['field_name'] ]['data'];
						} else {
							$this->data_mesgs['record'][ $field_definition_number['field_name'] ] = array();
							$tmp_mesg['record'][ $field_definition_number['field_name'] ]         = array();
						}
					}
				}
			}
		}
	}

	/**
	 * Store the data in the class variable $this->data_mesgs.
	 *
	 * Adjusts $this->tables_created
	 *
	 * @param array    $mesgs            The data to be stored.
	 * @param int      $local_mesg_type  Related element of $this->defn_mesgs.
	 * @param bool     $flush            Whether to flush any remaining data to the database.
	 */
	private function storeMesg( $mesgs, $local_mesg_type, $flush = false ) {
		// If no $mesgs and $flush, just flush buffer to the database.
		if ( ( null === $mesgs || empty( $mesgs ) ) && $flush && $this->file_buff ) {
			$this->bufferAndLoadMessages( array(), $flush );
			return;
		}

		$mesgs = $this->oneElementArraysSingle( $mesgs );

		if ( $this->file_buff ) {
			if ( $mesgs && null !== $local_mesg_type ) {
				$mesg_name = $this->data_mesg_info[ $this->defn_mesgs[ $local_mesg_type ]['global_mesg_num'] ]['mesg_name'];

				if ( 'hrv' === $mesg_name ) {
					foreach ( $mesgs['hrv']['times'] as &$value ) {
						if ( 65.535 === $value ) {
							$value = null;
						}
					}
					unset( $value );
					$mesgs['hrv']['times'] = json_encode( $mesgs['hrv']['times'] );
				}

				if ( ! isset( $this->tables_created[ $mesg_name ] ) ) {
					$this->tables_created[ $mesg_name ] = $this->create_table( $local_mesg_type );
					if ( ! $this->tables_created[ $mesg_name ] ) {
						return;
					}
				}

				// Check if we need to add a column to an already existing table.
				$this->check_for_columns_in_table( $mesgs, $local_mesg_type );
			}

			$mesgs_clean = $this->fixDataSingle( $mesgs );
			$mesgs_clean = $this->setUnitsSingle( $mesgs_clean );

			// $this->logger->debug( 'Storing messages: ' . print_r( $mesgs_clean, true ) );

			$this->bufferAndLoadMessages( $mesgs_clean, $flush );
		} else {
			// Store in $this->data_mesgs
			foreach ( $mesgs as $mesg_key => $mesg ) {
				if ( 'record' === $mesg_key ) {
					$timestamp = $mesg['timestamp'] ?? null;
					// $this->logger->debug( $mesg_key . ', timestamp: ' . $timestamp );
					foreach ( $mesg as $field_key => $field ) {
						if ( 'timestamp' === $field_key ) {
							$this->data_mesgs[ $mesg_key ][ $field_key ][] = $field;
						} else {
							$this->data_mesgs[ $mesg_key ][ $field_key ][ $timestamp ] = $field;
						}
					}
					// $this->logger->debug( 'Current record array: ' . print_r( $this->data_mesgs[ $mesg_key ], true ) );
				} else {
					foreach ( $mesg as $field_key => $field ) {
						$this->data_mesgs[ $mesg_key ][ $field_key ][] = $field;
					}
				}
			}
		}

		// $mesgs = $this->fixDataSingle( $mesgs );
		// $mesgs = $this->setUnitsSingle( $mesgs );
	}

	/**
	 * Buffer and load messages into the database.
	 *
	 * @param array $mesgs The messages to be buffered and loaded.
	 * @param bool  $flush Whether to flush any remaining data to the database.
	 * @return void
	 * @throws Exception If there is an error during the database operation.
	 */
	private function bufferAndLoadMessages( $mesgs, $flush ) {
		static $mesg_count   = 0;
		static $mesgs_buffer = array();

		if ( $mesgs ) {
			$count        = count( $mesgs );
			$mesgs_names  = array_keys( $mesgs );
			$mesgs_values = array_values( $mesgs );
			for ( $i=0; $i < $count; $i++ ) {
				$mesg_name = $mesgs_names[$i];
				$mesg      = $mesgs_values[$i];

				// $this->logger->debug( 'Buffering message: ' . $mesg_name . ' - ' . print_r( $mesg, true ) );

				// Ignore record messages which do not contain mandatory fields.
				if ( 'record' === $mesg_name ) {
					$has_mandatory_fields = $this->checkForMandatoryFields( array_keys( $mesg ) );
					if ( ! $has_mandatory_fields ) {
						// $this->logger->debug( 'Skipping record message with missing mandatory fields: ' . print_r( $mesg, true ) );
						continue;
					}
				}

				// Buffer the messages
				$mesgs_buffer[$mesg_name][] = array(
					'data'  => $mesg,
				);
			}
			$mesg_count += count( $mesgs );
		}

		if ( $flush || $mesg_count >= $this->buffer_size ) {
			// $this->logger->debug( 'Buffering ' . $mesg_count . ' messages into tables with message types: ' . implode( ', ', array_keys( $mesgs_buffer ) ) );
			// $this->logger->debug( 'Buffering messages: ' . print_r( $mesgs_buffer, true ) );

			foreach ( $mesgs_buffer as $table => $mesgs ) {
				if ( 'record' === $table ) {
					$this->storeRecordMesg( $mesgs, $table );
				} else {
					$this->storeNonRecordMesg( $mesgs, $table );
				}
			}

			$mesgs_buffer = array();
			$mesg_count   = 0;
		}
	}

	/**
	 * Enter non-record messages into their respective tables.
	 *
	 * @param array $mesgs The messages to be stored.
	 * @param string $table The table name.
	 */
	private function storeNonRecordMesg( $mesgs, $table ) {
		$table_name = $this->tables_created[ $table ]['location'];
		if ( ! $table_name ) {
			$this->logger->error( 'Table name not found for ' . $table );
			$this->logger->error( 'Table names: ' . print_r( $this->tables_created, true ) );
			throw new \Exception( 'Table name not found for ' . $table );
		}

		// Collect all unique columns across all messages, ignoring columns where all related data is '' or null
		$all_columns = array();
		foreach ( $mesgs as $mesg ) {
			foreach ( array_keys( $mesg['data'] ) as $column ) {
				$has_valid_data = false;
				foreach ( $mesgs as $check_mesg ) {
					if ( isset( $check_mesg['data'][ $column ] ) && '' !== $check_mesg['data'][ $column ] && null !== $check_mesg['data'][ $column ] ) {
						$has_valid_data = true;
						break;
					}
				}
				if ( $has_valid_data ) {
					$all_columns[] = '`' . $column . '`';
				}
			}
		}
		$all_columns = array_unique( $all_columns );
		// $this->logger->debug( 'All columns: ' . implode( ', ', $all_columns ) );

		$sql          = 'INSERT INTO ' . $table_name . ' (' . implode( ', ', $all_columns ) . ') VALUES ';
		$placeholders = array();
		$values       = array();

		foreach ( $mesgs as $mesg ) {
			// $this->logger->debug( $table . ' mesg: ' . print_r( $mesg, true ) );
			$row_placeholders = array();
			foreach ( $all_columns as $column ) {
				$column_name = trim( $column, '`' );
				if ( array_key_exists( $column_name, $mesg['data'] ) && null !== $mesg['data'][ $column_name ] && '' !== $mesg['data'][ $column_name ] ) {
					if (is_array( $mesg['data'][$column_name] )) {
						$row_placeholders[] = '?';
						$values[]           = json_encode( $mesg['data'][$column_name] ); // Convert array to JSON string
						// $this->logger->debug( "Loading {$table} message, value for {$column_name} is an array: " . print_r( $mesg['data'][$column_name], true ) );
					} else {
						$row_placeholders[] = '?';
						$values[]           = $mesg['data'][$column_name];
					}
				} else {
					$row_placeholders[] = 'NULL';
				}
			}
			$placeholders[] = '(' . implode( ', ', $row_placeholders ) . ')';
		}
		$sql .= implode( ', ', $placeholders ) . ';';

		try {
			$stmt = $this->db->prepare( $sql );
			$stmt->execute( $values );
		} catch ( \PDOException $e ) {
			$this->logger->error( 'Error inserting data into table, ' . $table_name . ': ' . $e->getMessage() );
			$this->logger->error( ' columns: ' . implode( ', ', $all_columns ) );
			$this->logger->error( ' values:  ' . implode( ', ', $values ) );
			throw $e;
		}

		// $this->logger->debug( 'Loading messages into table: ' . $table_name );
	}

	/**
	 * Enter record messages into their respective tables.
	 *
	 * Adds in spatial points and special indexes.
	 *
	 * @param array $mesgs The messages to be stored.
	 * @param string $table The table name.
	 */
	private function storeRecordMesg( $mesgs, $table ) {
		$table_name = $this->tables_created[ $table ]['location'];
		if ( ! $table_name ) {
			$this->logger->error( 'Table name not found for ' . $table );
			$this->logger->error( 'Table names: ' . print_r( $this->tables_created, true ) );
			throw new \Exception( 'Table name not found for ' . $table );
		}

		// Collect all unique columns across all messages
		$all_columns = array();
		foreach ( $mesgs as $mesg ) {
			$all_columns = array_unique(
				array_merge(
					$all_columns,
					array_map(
						function ( $column ) {
							return '`' . $column . '`';
						},
						array_keys( $mesg['data'] )
					)
				)
			);
		}

		// Add spatial_point column if position_lat and position_long exist
		if ( in_array( '`position_lat`', $all_columns, true ) && in_array( '`position_long`', $all_columns, true ) ) {
			$all_columns[] = '`spatial_point`';
		}

		// Trim ` from the column names.
		$all_columns_no_ticks = array();
		foreach ( $all_columns as $column ) {
			$all_columns_no_ticks[] = trim( $column, '`' );
		}

		// Confirm that all columns are represented in the table.
		// $columns_in_table = $this->db->query( 'SHOW COLUMNS FROM ' . $table_name )->fetchAll( \PDO::FETCH_COLUMN );
		$columns_in_table = array_column( $this->tables_created[ $table ]['columns'], 'field_name' );
		// $this->logger->debug( 'Columns in table: ' . print_r( $columns_in_table, true ) );
		$missing_columns = array_filter(
			$all_columns_no_ticks,
			function ( $column ) use ( $columns_in_table ) {
				return !in_array( $column, $columns_in_table, true );
			}
		);
		if ( ! empty( $missing_columns ) ) {
			$this->logger->error( 'Missing columns in table ' . $table_name . ': ' . implode( ', ', $missing_columns ) );
			$this->logger->error( ' All columns in messages: ' . implode( ', ', $all_columns_no_ticks ) );
			$this->logger->error( ' All columns in table:    ' . implode( ', ', $columns_in_table ) );
			throw new \Exception( 'Missing columns in table ' . $table_name . ': ' . implode( ', ', $missing_columns ) );
		}

		// $this->logger->debug( 'All columns: ' . implode( ', ', $all_columns ) );

		$sql    = 'INSERT INTO ' . $table_name . ' (' . implode( ', ', $all_columns ) . ') VALUES ';
		$values = array();
		foreach ( $mesgs as $mesg ) {
			if ( ! isset( $mesg['data']['position_lat'] ) || ! isset( $mesg['data']['position_long'] ) ) {
				continue;
			}

			$placeholders = array();
			foreach ( $all_columns_no_ticks as $column ) {
				if ( $column === 'spatial_point' ) {
					if ( isset( $mesg['data']['position_lat'] ) && isset( $mesg['data']['position_long'] ) ) {
						$lat            = $mesg['data']['position_lat'];
						$lon            = $mesg['data']['position_long'];
						$placeholders[] = "POINT($lon, $lat)";
					} else {
						$placeholders[] = 'NULL';
					}
				} elseif ( array_key_exists( $column, $mesg['data'] ) ) {
					$placeholders[] = $this->db->quote( $mesg['data'][ $column ] );
				} else {
					$placeholders[] = 'NULL';
				}
			}
			$values[] = '(' . implode( ', ', $placeholders ) . ')';
		}
		$sql .= implode( ', ', $values ) . ';';

		// $this->logger->debug( 'SQL: ' . $sql );

		try {
			$this->db->exec( $sql );
		} catch ( \PDOException $e ) {
			$this->logger->error( 'Error inserting data into table, ' . $table_name . ': ' . $e->getMessage() );
			$this->logger->error( ' columns: ' . implode( ', ', $all_columns ) );
			$this->logger->error( ' values:  ' . implode( ', ', $values ) );
			throw $e;
		}

		// $this->logger->debug( 'Loading messages into table: ' . $table_name );
	}


	/**
	 * Create a table for the given message type.
	 *
	 * @param int $local_mesg_type The message type.
	 * @return bool True if the table was created successfully, null otherwise.
	 */
	private function create_table( $local_mesg_type ) {
		$mesg_name  = $this->data_mesg_info[ $this->defn_mesgs[ $local_mesg_type ]['global_mesg_num'] ]['mesg_name'];
		$table_name = $this->data_table . $mesg_name;
		$table_name = $this->cleanTableName( $table_name );
		$columns    = array();
		$units      = isset( $this->options['units'] ) ? strtolower( $this->options['units'] ) : 'metric';

		try {
			$this->db->exec( 'DROP TABLE IF EXISTS ' . $table_name );
		} catch ( \PDOException $e ) {
			$this->logger->error( 'Error dropping table, ' . $table_name . ': ' . $e->getMessage() );
			throw $e;
		}

		foreach ( $this->defn_mesgs[ $local_mesg_type ]['field_defns'] as $field_defn ) {
			if ( isset( $this->data_mesg_info[ $this->defn_mesgs[ $local_mesg_type ]['global_mesg_num'] ]['field_defns'][ $field_defn['field_definition_number'] ] ) ) {
				$columns[] = array(
					'field_name' => $this->cleanTableName( $this->data_mesg_info[ $this->defn_mesgs[ $local_mesg_type ]['global_mesg_num'] ]['field_defns'][ $field_defn['field_definition_number'] ]['field_name'] ),
					'type'       => $this->data_mesg_info[ $this->defn_mesgs[ $local_mesg_type ]['global_mesg_num'] ]['field_defns'][ $field_defn['field_definition_number'] ][ $units ],
				);
			}
		}

		if ($mesg_name === 'record') {
			// Ensure mandatory columns are present for 'record' table
			$mandatory_columns = array(
				array( 'field_name' => $this->data_mesg_info[20]['field_defns'][0]['field_name'], 'type' => $this->data_mesg_info[20]['field_defns'][0][ $units ] ),      // position_lat.
				array( 'field_name' => $this->data_mesg_info[20]['field_defns'][1]['field_name'], 'type' => $this->data_mesg_info[20]['field_defns'][1][ $units ] ),      // position_long.
				array( 'field_name' => $this->data_mesg_info[20]['field_defns'][5]['field_name'], 'type' => $this->data_mesg_info[20]['field_defns'][5][ $units ] ),      // distance.
				array( 'field_name' => $this->data_mesg_info[20]['field_defns'][253]['field_name'], 'type' => $this->data_mesg_info[20]['field_defns'][253][ $units ] ),  // timestamp.
				array( 'field_name' => 'timestamp', 'type' => 'INT UNSIGNED' ),
			);
			$existing_fields   = array_column( $columns, 'field_name' );
			foreach ($mandatory_columns as $mandatory) {
				if (!in_array( $mandatory['field_name'], $existing_fields, true )) {
					$columns[] = $mandatory;
				}
			}
		}

		// $this->logger->debug( 'Creating table: ' . $table_name . ' with columns: ' . print_r( $columns, true ) );

		$column_names = array_column( $columns, 'field_name' );

		$sql = 'CREATE TABLE ' . $table_name . ' (id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, ';
		foreach ($columns as $column) {
			$sql .= '`' . $column['field_name'] . '` ' . $column['type'] . ' DEFAULT NULL, ';
		}

		// If 'record', add spatial point and indexes.
		if ( 'record' === $mesg_name ) {
			$sql .= '`paused` TINYINT(1) DEFAULT NULL, ';
			$sql .= '`stopped` TINYINT(1) DEFAULT NULL, ';
			$sql .= '`spatial_point` POINT NOT NULL, ';
			$sql .= 'SPATIAL INDEX spatial_idx (`spatial_point`), ';
			$sql .= 'INDEX distance (`distance`), ';
			$sql .= 'INDEX time_idx (`timestamp`), ';

			$columns[] = array(
				'field_name' => 'paused',
				'type'       => 'TINYINT(1) DEFAULT NULL',
			);
			$columns[] = array(
				'field_name' => 'stopped',
				'type'       => 'TINYINT(1) DEFAULT NULL',
			);
			$columns[] = array(
				'field_name' => 'spatial_point',
				'type'       => 'POINT NOT NULL',
			);
		}

		$sql = rtrim( $sql, ', ' ) . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;';

		try {
			$this->db->exec( $sql );
			// $this->logger->debug( 'Table created successfully: ' . $table_name . ' with columns ' . implode( ', ', $column_names ) . ' + more for record messages' );
		} catch (\PDOException $e) {
			$this->logger->error( 'Error creating table, ' . $table_name . ': ' . $e->getMessage() );
			throw $e;
		}

		$table_info = array(
			'location' => $table_name,
			'columns'  => $columns,
		);

		return $table_info;
	}

	/**
	 * Check for mandatory fields.
	 *
	 * @param array $field_names The field names to check against.
	 * @return bool True if all mandatory field are present, false otherwise.
	 */
	private function checkForMandatoryFields( $field_names ) {
		static $mandatory_fields = array( 'position_lat', 'position_long', 'timestamp', 'distance' );

		foreach ( $mandatory_fields as $field ) {
			if ( ! in_array( $field, $field_names, true ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Check that all elements in a message are present as columns in the
	 * related table.
	 *
	 * @param array $mesgs The messages to be checked.
	 * @param int   $local_mesg_type The local message type.
	 */
	private function check_for_columns_in_table( $mesgs, $local_mesg_type ) {
		foreach ( $mesgs as $mesg_name => $mesg ) {
			$table_columns = array_column( $this->tables_created[ $mesg_name ]['columns'], 'field_name' );
			$mesg_elements = array_keys( $mesg );

			$missing_columns = array_filter(
				$mesg_elements,
				function ( $element ) use ( $table_columns ) {
					return !in_array( $element, $table_columns, true );
				}
			);

			if ( ! empty( $missing_columns ) ) {
				// $this->logger->debug( 'Missing columns in ' . $mesg_name . ' table, local_mesg_type = ' . $local_mesg_type . ': ' . implode( ', ', $missing_columns ) );
				// $this->logger->debug( '  Table columns:    ' . implode( ', ', $table_columns ) );
				// $this->logger->debug( '  Message elements: ' . implode( ', ', $mesg_elements ) );
				$this->add_columns_to_table( $mesg_name, $local_mesg_type, $table_columns );
			}
		}
	}

	/**
	 * Add columns to an existing table.
	 *
	 * @param string $mesg_name       The name of the table.
	 * @param int    $local_mesg_type The local message type.
	 * @param array  $table_columns   The current columns in the table.
	 */
	private function add_columns_to_table( $mesg_name, $local_mesg_type, $table_columns ) {
		$table_name = $this->tables_created[ $mesg_name ]['location'];
		if ( ! $table_name ) {
			$this->logger->error( 'Table name not found for ' . $mesg_name );
			$this->logger->error( 'Table names: ' . print_r( $this->tables_created, true ) );
			throw new \Exception( 'Table name not found for ' . $mesg_name );
		}

		$new_columns = array();

		foreach ( $this->defn_mesgs[ $local_mesg_type ]['field_defns'] as $field_defn ) {
			// $this->logger->debug( $mesg_name . ' field_defn: ' . print_r( $field_defn, true ) );

			$column_def = $this->data_mesg_info[ $this->defn_mesgs[ $local_mesg_type ]['global_mesg_num'] ]['field_defns'][ $field_defn['field_definition_number'] ] ?? null;
			$units      = isset( $this->options['units'] ) ? strtolower( $this->options['units'] ) : 'metric';

			if ( isset( $column_def['field_name'] ) && ! in_array( $column_def['field_name'], $table_columns, true )) {
				$this->logger->debug( 'Adding column: ' . $column_def['field_name'] . ' to ' . $mesg_name . ' table' );
				$new_columns[] = array(
					'field_name' => $this->cleanTableName( $column_def['field_name'] ),
					'type'       => $column_def[ $units ],
				);
			}
		}

		// TODO: need to handle $this->defn_mesgs[ $local_mesg_type ]['dev_field_defns'].
		foreach ( $this->defn_mesgs[ $local_mesg_type ]['dev_field_definitions'] as $dev_field_defn ) {
			// $this->logger->debug( $mesg_name . ' dev_field_defn: ' . print_r( $dev_field_defn, true ) );

			$column_def = $this->dev_field_descriptions[ $dev_field_defn['developer_data_index'] ][ $dev_field_defn['field_definition_number'] ] ?? null;

			// $this->logger->debug( 'Dev field column_def: ' . print_r( $column_def, true ) );

			if ( isset( $column_def['field_name'] ) && ! in_array( $column_def['field_name'], $table_columns, true )) {
				// $this->logger->debug( 'Adding column: ' . $column_def['field_name'] . ' to ' . $mesg_name . ' table' );
				// $this->logger->debug( ' column_def: ' . print_r( $column_def, true ) );

				$base_type = $column_def['fit_base_type_id'] ?? null;
				$type      = $this->enum_data['base_type'][ $base_type ] ?? null;

				// add size to string and byte types
				if ( in_array( $base_type, array( 7, 13 ), true ) ) {
					$size = $dev_field_defn['size'] ?? null;
					if ( $size ) {
						$type .= '(' . $size . ')';
					}
				}

				$new_column = array(
					'field_name' => $this->cleanTableName( $column_def['field_name'] ),
					'type'       => $type,
				);

				$new_columns[] = $new_column;

				$this->logger->debug( 'New column: ' . $new_column['field_name'] . ', type: ' . $new_column['type'] );
			}
		}
		// $this->logger->debug( 'New columns: ' . print_r( $new_columns, true ) );

		if ( empty( $new_columns ) ) {
			$this->logger->debug( 'No new columns to add to table ' . $table_name );
			return;
		}

		$sql = 'ALTER TABLE ' . $table_name;
		foreach ( $new_columns as $column ) {
			$sql .= ' ADD COLUMN `' . $column['field_name'] . '` ' . $column['type'] . ' DEFAULT NULL,';
			$this->tables_created[ $mesg_name ]['columns'][] = array(
				'field_name' => $column['field_name'],
				'type'       => $column['type'],
			);
		}
		$sql = rtrim( $sql, ', ' ) . ';';
		// $this->logger->debug( 'SQL to add columns: ' . $sql );

		try {
			$this->db->exec( $sql );
			$this->logger->debug( 'Columns added to table: ' . $table_name );
		} catch ( \PDOException $e ) {
			$this->logger->error( 'Error adding columns to table, ' . $table_name . ': ' . $e->getMessage() );
			throw $e;
		}
	}

	/**
	 * Formats memory usage in a human-readable format.
	 *
	 * @param int $bytes Memory usage in bytes.
	 * @return string Formatted memory usage with appropriate unit.
	 */
	private function formatMemoryUsage( $bytes ) {
		if ($bytes < 1024) {
			return $bytes . ' B';
		} elseif ($bytes < 1048576) {
			return round( $bytes / 1024, 2 ) . ' KB';
		} elseif ($bytes < 1073741824) {
			return round( $bytes / 1048576, 2 ) . ' MB';
		} else {
			return round( $bytes / 1073741824, 2 ) . ' GB';
		}
	}


	/**
	 * If the user has requested for the data to be fixed, identify the missing keys for that data.
	 */
	private function fixData( $options, $queue = null ) {
		$lock_expire = $this->get_lock_expiration( $queue );

		// By default the constant FIT_UNIX_TS_DIFF will be added to timestamps, which have field type of date_time (or local_date_time).
		// Timestamp fields (field number == 253) converted after being unpacked in $this->readDataRecords().
		if ( ! $this->garmin_timestamps ) {
			$date_times = array(
				array(
					'message_name' => 'activity',
					'field_name'   => 'local_timestamp',
				),
				array(
					'message_name' => 'course_point',
					'field_name'   => 'timestamp',
				),
				array(
					'message_name' => 'file_id',
					'field_name'   => 'time_created',
				),
				array(
					'message_name' => 'goal',
					'field_name'   => 'end_date',
				),
				array(
					'message_name' => 'goal',
					'field_name'   => 'start_date',
				),
				array(
					'message_name' => 'lap',
					'field_name'   => 'start_time',
				),
				array(
					'message_name' => 'length',
					'field_name'   => 'start_time',
				),
				array(
					'message_name' => 'monitoring',
					'field_name'   => 'local_timestamp',
				),
				array(
					'message_name' => 'monitoring_info',
					'field_name'   => 'local_timestamp',
				),
				array(
					'message_name' => 'obdii_data',
					'field_name'   => 'start_timestamp',
				),
				array(
					'message_name' => 'schedule',
					'field_name'   => 'scheduled_time',
				),
				array(
					'message_name' => 'schedule',
					'field_name'   => 'time_created',
				),
				array(
					'message_name' => 'segment_lap',
					'field_name'   => 'start_time',
				),
				array(
					'message_name' => 'session',
					'field_name'   => 'start_time',
				),
				array(
					'message_name' => 'timestamp_correlation',
					'field_name'   => 'local_timestamp',
				),
				array(
					'message_name' => 'timestamp_correlation',
					'field_name'   => 'system_timestamp',
				),
				array(
					'message_name' => 'training_file',
					'field_name'   => 'time_created',
				),
				array(
					'message_name' => 'video_clip',
					'field_name'   => 'end_timestamp',
				),
				array(
					'message_name' => 'video_clip',
					'field_name'   => 'start_timestamp',
				),
			);

			foreach ( $date_times as $date_time ) {
				if ( isset( $this->data_mesgs[ $date_time['message_name'] ][ $date_time['field_name'] ] ) ) {
					if ( is_array( $this->data_mesgs[ $date_time['message_name'] ][ $date_time['field_name'] ] ) ) {
						foreach ( $this->data_mesgs[ $date_time['message_name'] ][ $date_time['field_name'] ] as &$element ) {
							$lock_expire = $this->maybe_set_lock_expiration( $queue, $lock_expire );
							// $this->logger->debug( 'Adding ' . FIT_UNIX_TS_DIFF . ' to timestamp: ' . $element );

							$element += FIT_UNIX_TS_DIFF;
						}
					} else {
						$lock_expire = $this->maybe_set_lock_expiration( $queue, $lock_expire );
						// $this->logger->debug( 'Adding ' . FIT_UNIX_TS_DIFF . ' to: ' . $date_time['message_name'] . '->' . $date_time['field_name'] . ': ' . $this->data_mesgs[ $date_time['message_name'] ][ $date_time['field_name'] ] );

						$this->data_mesgs[ $date_time['message_name'] ][ $date_time['field_name'] ] += FIT_UNIX_TS_DIFF;
					}
				}
			}
		}

		// $this->logger->debug( 'phpFITFileAnalysis->fixData(): finished adjusting timestamps at ' . gmdate( 'H:i:s' ) );
		$lock_expire = $this->maybe_set_lock_expiration( $queue, $lock_expire );

		// Find messages that have been unpacked as unsigned integers that should be signed integers.
		// http://php.net/manual/en/function.pack.php - signed integers endianness is always machine dependent.
		// 131    s    signed short (always 16 bit, machine byte order)
		// 133    l    signed long (always 32 bit, machine byte order)
		// 142    q    signed long long (always 64 bit, machine byte order)
		foreach ( $this->defn_mesgs_all as $mesg ) {
			if ( isset( $this->data_mesg_info[ $mesg['global_mesg_num'] ] ) ) {
				$mesg_name = $this->data_mesg_info[ $mesg['global_mesg_num'] ]['mesg_name'];

				foreach ( $mesg['field_defns'] as $field ) {
					// Convert uint16 to sint16
					if ( $field['base_type'] === 131 && isset( $this->data_mesg_info[ $mesg['global_mesg_num'] ]['field_defns'][ $field['field_definition_number'] ]['field_name'] ) ) {
						$field_name = $this->data_mesg_info[ $mesg['global_mesg_num'] ]['field_defns'][ $field['field_definition_number'] ]['field_name'];
						if ( isset( $this->data_mesgs[ $mesg_name ][ $field_name ] ) ) {
							if ( is_array( $this->data_mesgs[ $mesg_name ][ $field_name ] ) ) {
								foreach ( $this->data_mesgs[ $mesg_name ][ $field_name ] as &$v ) {
									$lock_expire = $this->maybe_set_lock_expiration( $queue, $lock_expire );
									if ( PHP_INT_SIZE === 8 && $v > 0x7FFF ) {
										$v -= 0x10000;
									}
									if ( $v > 0x7FFF ) {
										$v = -1 * ( $v - 0x7FFF );
									}
								}
							} elseif ( $this->data_mesgs[ $mesg_name ][ $field_name ] > 0x7FFF ) {
								if ( PHP_INT_SIZE === 8 ) {
									$this->data_mesgs[ $mesg_name ][ $field_name ] -= 0x10000;
								}
								$this->data_mesgs[ $mesg_name ][ $field_name ] = -1 * ( $this->data_mesgs[ $mesg_name ][ $field_name ] - 0x7FFF );
							}
						}
					} // Convert uint32 to sint32
					elseif ( $field['base_type'] === 133 && isset( $this->data_mesg_info[ $mesg['global_mesg_num'] ]['field_defns'][ $field['field_definition_number'] ]['field_name'] ) ) {
						$field_name = $this->data_mesg_info[ $mesg['global_mesg_num'] ]['field_defns'][ $field['field_definition_number'] ]['field_name'];
						if ( isset( $this->data_mesgs[ $mesg_name ][ $field_name ] ) ) {
							if ( is_array( $this->data_mesgs[ $mesg_name ][ $field_name ] ) ) {
								foreach ( $this->data_mesgs[ $mesg_name ][ $field_name ] as &$v ) {
									$lock_expire = $this->maybe_set_lock_expiration( $queue, $lock_expire );
									if ( PHP_INT_SIZE === 8 && $v > 0x7FFFFFFF ) {
										$v -= 0x100000000;
									}
									if ( $v > 0x7FFFFFFF ) {
										$v = -1 * ( $v - 0x7FFFFFFF );
									}
								}
							} elseif ( $this->data_mesgs[ $mesg_name ][ $field_name ] > 0x7FFFFFFF ) {
								if ( PHP_INT_SIZE === 8 ) {
									$this->data_mesgs[ $mesg_name ][ $field_name ] -= 0x100000000;

								}
								if ( $this->data_mesgs[ $mesg_name ][ $field_name ] > 0x7FFFFFFF ) {
									$this->data_mesgs[ $mesg_name ][ $field_name ] = -1 * ( $this->data_mesgs[ $mesg_name ][ $field_name ] - 0x7FFFFFFF );
								}
							}
						}
					} // Convert uint64 to sint64
					elseif ( $field['base_type'] === 142 && isset( $this->data_mesg_info[ $mesg['global_mesg_num'] ]['field_defns'][ $field['field_definition_number'] ]['field_name'] ) ) {
						$field_name = $this->data_mesg_info[ $mesg['global_mesg_num'] ]['field_defns'][ $field['field_definition_number'] ]['field_name'];
						if ( isset( $this->data_mesgs[ $mesg_name ][ $field_name ] ) ) {
							if ( is_array( $this->data_mesgs[ $mesg_name ][ $field_name ] ) ) {
								foreach ( $this->data_mesgs[ $mesg_name ][ $field_name ] as &$v ) {
									$lock_expire = $this->maybe_set_lock_expiration( $queue, $lock_expire );
									if ( PHP_INT_SIZE === 8 && $v > 0x7FFFFFFFFFFFFFFF ) {
										$v -= 0x10000000000000000;
									}
									if ( $v > 0x7FFFFFFFFFFFFFFF ) {
										$v = -1 * ( $v - 0x7FFFFFFFFFFFFFFF );
									}
								}
							} elseif ( $this->data_mesgs[ $mesg_name ][ $field_name ] > 0x7FFFFFFFFFFFFFFF ) {
								if ( PHP_INT_SIZE === 8 ) {
									$this->data_mesgs[ $mesg_name ][ $field_name ] -= 0x10000000000000000;
								}
								$this->data_mesgs[ $mesg_name ][ $field_name ] = -1 * ( $this->data_mesgs[ $mesg_name ][ $field_name ] - 0x7FFFFFFFFFFFFFFF );
							}
						}
					}
				}
			}
		}

		// $this->logger->debug( 'phpFITFileAnalysis->fixData(): finished unsigned int check at ' . gmdate( 'H:i:s' ) );
		$lock_expire = $this->maybe_set_lock_expiration( $queue, $lock_expire );

		// Remove duplicate timestamps and store original before interpolating
		if ( isset( $this->data_mesgs['record']['timestamp'] ) && is_array( $this->data_mesgs['record']['timestamp'] ) ) {
			$this->data_mesgs['record']['timestamp']          = array_unique( $this->data_mesgs['record']['timestamp'] );
			$this->data_mesgs['record']['timestamp_original'] = $this->data_mesgs['record']['timestamp'];
		}

		$lock_expire = $this->maybe_set_lock_expiration( $queue, $lock_expire );

		// Return if no option set
		if ( empty( $options['fix_data'] ) && empty( $options['data_every_second'] ) ) {
			return;
		}

		if ( ! isset( $this->data_mesgs['record'] ) ) {
			return;
		}

		// If $options['data_every_second'], then create timestamp array for every second from min to max
		if ( ! empty( $options['data_every_second'] ) && ! ( is_string( $options['data_every_second'] ) && strtolower( $options['data_every_second'] ) === 'false' ) ) {
			// If user has not specified the data to be fixed, assume all
			if ( empty( $options['fix_data'] ) ) {
				$options['fix_data'] = array( 'all' );
			}

			$min_ts = min( $this->data_mesgs['record']['timestamp'] );
			$max_ts = max( $this->data_mesgs['record']['timestamp'] );
			unset( $this->data_mesgs['record']['timestamp'] );
			for ( $i = $min_ts; $i <= $max_ts; ++$i ) {
				$lock_expire = $this->maybe_set_lock_expiration( $queue, $lock_expire );

				$this->data_mesgs['record']['timestamp'][] = $i;
			}
		}

		$lock_expire = $this->maybe_set_lock_expiration( $queue, $lock_expire );

		// Check if valid option(s) provided
		array_walk(
			$options['fix_data'],
			function ( &$value ) {
				$value = strtolower( $value );
			}
		);  // Make all lower-case.
		if ( count( array_intersect( array( 'all', 'cadence', 'distance', 'heart_rate', 'lat_lon', 'speed', 'power', 'altitude', 'enhanced_speed', 'enhanced_altitude' ), $options['fix_data'] ) ) === 0 ) {
			throw new \Exception( 'phpFITFileAnalysis->fixData(): option not valid!' );
		}

		$bCadence = $bDistance = $bHeartRate = $bLatitudeLongitude = $bSpeed = $bPower = $bAltitude = $bEnhancedSpeed = $bEnhancedAltitude = false;
		if ( in_array( 'all', $options['fix_data'] ) ) {
			$bCadence           = isset( $this->data_mesgs['record']['cadence'] );
			$bDistance          = isset( $this->data_mesgs['record']['distance'] );
			$bHeartRate         = isset( $this->data_mesgs['record']['heart_rate'] );
			$bLatitudeLongitude = isset( $this->data_mesgs['record']['position_lat'] ) && isset( $this->data_mesgs['record']['position_long'] );
			$bSpeed             = isset( $this->data_mesgs['record']['speed'] );
			$bPower             = isset( $this->data_mesgs['record']['power'] );
			$bAltitude          = isset( $this->data_mesgs['record']['altitude'] );
			$bEnhancedSpeed     = isset( $this->data_mesgs['record']['enhanced_speed'] );
			$bEnhancedAltitude  = isset( $this->data_mesgs['record']['enhanced_altitude'] );
		} elseif ( isset( $this->data_mesgs['record']['timestamp'] ) ) {
				$count_timestamp = count( $this->data_mesgs['record']['timestamp'] );  // No point try to insert missing values if we know there aren't any.
			if ( isset( $this->data_mesgs['record']['cadence'] ) && is_array( $this->data_mesgs['record']['cadence'] ) ) {
				$bCadence = ( count( $this->data_mesgs['record']['cadence'] ) === $count_timestamp ) ? false : in_array( 'cadence', $options['fix_data'] );
			}
			$lock_expire = $this->maybe_set_lock_expiration( $queue, $lock_expire );
			if ( isset( $this->data_mesgs['record']['distance'] ) && is_array( $this->data_mesgs['record']['distance'] ) ) {
				$bDistance = ( count( $this->data_mesgs['record']['distance'] ) === $count_timestamp ) ? false : in_array( 'distance', $options['fix_data'] );
			}
			$lock_expire = $this->maybe_set_lock_expiration( $queue, $lock_expire );
			if ( isset( $this->data_mesgs['record']['heart_rate'] ) && is_array( $this->data_mesgs['record']['heart_rate'] ) ) {
				$bHeartRate = ( count( $this->data_mesgs['record']['heart_rate'] ) === $count_timestamp ) ? false : in_array( 'heart_rate', $options['fix_data'] );
			}
			$lock_expire = $this->maybe_set_lock_expiration( $queue, $lock_expire );
			if ( isset( $this->data_mesgs['record']['position_lat'] ) && isset( $this->data_mesgs['record']['position_long'] ) && is_array( $this->data_mesgs['record']['position_lat'] ) ) {
				$bLatitudeLongitude = ( count( $this->data_mesgs['record']['position_lat'] ) === $count_timestamp
					&& count( $this->data_mesgs['record']['position_long'] ) === $count_timestamp ) ? false : in_array( 'lat_lon', $options['fix_data'] );
			}
			$lock_expire = $this->maybe_set_lock_expiration( $queue, $lock_expire );
			if ( isset( $this->data_mesgs['record']['speed'] ) && is_array( $this->data_mesgs['record']['speed'] ) ) {
				$bSpeed = ( count( $this->data_mesgs['record']['speed'] ) === $count_timestamp ) ? false : in_array( 'speed', $options['fix_data'] );
			}
			$lock_expire = $this->maybe_set_lock_expiration( $queue, $lock_expire );
			if ( isset( $this->data_mesgs['record']['power'] ) && is_array( $this->data_mesgs['record']['power'] ) ) {
				$bPower = ( count( $this->data_mesgs['record']['power'] ) === $count_timestamp ) ? false : in_array( 'power', $options['fix_data'] );
			}
			$lock_expire = $this->maybe_set_lock_expiration( $queue, $lock_expire );
			if ( isset( $this->data_mesgs['record']['altitude'] ) && is_array( $this->data_mesgs['record']['altitude'] ) ) {
				$bAltitude = ( count( $this->data_mesgs['record']['altitude'] ) === $count_timestamp ) ? false : in_array( 'altitude', $options['fix_data'] );
			}
			$lock_expire = $this->maybe_set_lock_expiration( $queue, $lock_expire );
			if ( isset( $this->data_mesgs['record']['enhanced_speed'] ) && is_array( $this->data_mesgs['record']['enhanced_speed'] ) ) {
				$bEnhancedSpeed = ( count( $this->data_mesgs['record']['enhanced_speed'] ) === $count_timestamp ) ? false : in_array( 'enhanced_speed', $options['fix_data'] );
			}
			$lock_expire = $this->maybe_set_lock_expiration( $queue, $lock_expire );
			if ( isset( $this->data_mesgs['record']['enhanced_altitude'] ) && is_array( $this->data_mesgs['record']['enhanced_altitude'] ) ) {
				$bEnhancedAltitude = ( count( $this->data_mesgs['record']['enhanced_altitude'] ) === $count_timestamp ) ? false : in_array( 'enhanced_altitude', $options['fix_data'] );
			}
		}

		// $this->logger->debug( 'phpFITFileAnalysis->fixData(): set up fields to check at ' . gmdate( 'H:i:s' ) );

		$missing_distance_keys          = array();
		$missing_hr_keys                = array();
		$missing_lat_keys               = array();
		$missing_lon_keys               = array();
		$missing_speed_keys             = array();
		$missing_power_keys             = array();
		$missing_altitude_keys          = array();
		$missing_enhanced_speed_keys    = array();
		$missing_enhanced_altitude_keys = array();

		foreach ( $this->data_mesgs['record']['timestamp'] as $timestamp ) {
			$lock_expire = $this->maybe_set_lock_expiration( $queue, $lock_expire );
			if ( $bCadence ) {  // Assumes all missing cadence values are zeros
				if ( ! isset( $this->data_mesgs['record']['cadence'][ $timestamp ] ) ) {
					$this->data_mesgs['record']['cadence'][ $timestamp ] = 0;
				}
			}
			if ( $bDistance ) {
				if ( ! isset( $this->data_mesgs['record']['distance'][ $timestamp ] ) ) {
					$missing_distance_keys[] = $timestamp;
				}
			}
			if ( $bHeartRate ) {
				if ( ! isset( $this->data_mesgs['record']['heart_rate'][ $timestamp ] ) ) {
					$missing_hr_keys[] = $timestamp;
				}
			}
			if ( $bLatitudeLongitude ) {
				if ( ! isset( $this->data_mesgs['record']['position_lat'][ $timestamp ] ) ) {
					$missing_lat_keys[] = $timestamp;
				}
				if ( ! isset( $this->data_mesgs['record']['position_long'][ $timestamp ] ) ) {
					$missing_lon_keys[] = $timestamp;
				}
			}
			if ( $bSpeed ) {
				if ( ! isset( $this->data_mesgs['record']['speed'][ $timestamp ] ) ) {
					$missing_speed_keys[] = $timestamp;
				}
			}
			if ( $bPower ) {
				if ( ! isset( $this->data_mesgs['record']['power'][ $timestamp ] ) ) {
					$missing_power_keys[] = $timestamp;
				}
			}
			if ( $bAltitude ) {
				if ( ! isset( $this->data_mesgs['record']['altitude'][ $timestamp ] ) ) {
					$missing_altitude_keys[] = $timestamp;
				}
			}
			if ( $bEnhancedSpeed ) {
				if ( ! isset( $this->data_mesgs['record']['enhanced_speed'][ $timestamp ] ) ) {
					$missing_enhanced_speed_keys[] = $timestamp;
				}
			}
			if ( $bEnhancedAltitude ) {
				if ( ! isset( $this->data_mesgs['record']['enhanced_altitude'][ $timestamp ] ) ) {
					$missing_enhanced_altitude_keys[] = $timestamp;
				}
			}
		}

		// $this->logger->debug( 'phpFITFileAnalysis->fixData(): finished checking for missing data at ' . gmdate( 'H:i:s' ) );

		$paused_timestamps = $this->isPaused();

		$lock_expire = $this->maybe_set_lock_expiration( $queue, $lock_expire );

		$this->filterPauseGapThreshold( $paused_timestamps );

		if ( $bCadence ) {
			ksort( $this->data_mesgs['record']['cadence'] );  // no interpolation; zeros added earlier
			// $this->logger->debug( 'phpFITFileAnalysis->fixData(): finished adding missing cadence data at ' . gmdate( 'H:i:s' ) );
		}
		$lock_expire = $this->maybe_set_lock_expiration( $queue, $lock_expire );
		if ( $bDistance ) {
			$lock_expire = $this->interpolateMissingData( $missing_distance_keys, $this->data_mesgs['record']['distance'], false, $paused_timestamps, $queue, $lock_expire );
			// $this->logger->debug( 'phpFITFileAnalysis->fixData(): finished adding missing distance data at ' . gmdate( 'H:i:s' ) );
		}
		$lock_expire = $this->maybe_set_lock_expiration( $queue, $lock_expire );
		if ( $bHeartRate ) {
			$lock_expire = $this->interpolateMissingData( $missing_hr_keys, $this->data_mesgs['record']['heart_rate'], true, $paused_timestamps, $queue, $lock_expire );
			// $this->logger->debug( 'phpFITFileAnalysis->fixData(): finished adding missing HR data at ' . gmdate( 'H:i:s' ) );
		}
		$lock_expire = $this->maybe_set_lock_expiration( $queue, $lock_expire );
		if ( $bLatitudeLongitude ) {
			$lock_expire = $this->interpolateMissingData( $missing_lat_keys, $this->data_mesgs['record']['position_lat'], false, $paused_timestamps, $queue, $lock_expire );
			$lock_expire = $this->interpolateMissingData( $missing_lon_keys, $this->data_mesgs['record']['position_long'], false, $paused_timestamps, $queue, $lock_expire );
			// $this->logger->debug( 'phpFITFileAnalysis->fixData(): finished adding missing lat/long data at ' . gmdate( 'H:i:s' ) );
		}
		$lock_expire = $this->maybe_set_lock_expiration( $queue, $lock_expire );
		if ( $bSpeed ) {
			$lock_expire = $this->interpolateMissingData( $missing_speed_keys, $this->data_mesgs['record']['speed'], false, $paused_timestamps, $queue, $lock_expire );
			// $this->logger->debug( 'phpFITFileAnalysis->fixData(): finished adding missing speed data at ' . gmdate( 'H:i:s' ) );
		}
		$lock_expire = $this->maybe_set_lock_expiration( $queue, $lock_expire );
		if ( $bPower ) {
			$lock_expire = $this->interpolateMissingData( $missing_power_keys, $this->data_mesgs['record']['power'], true, $paused_timestamps, $queue, $lock_expire );
			// $this->logger->debug( 'phpFITFileAnalysis->fixData(): finished adding missing power data at ' . gmdate( 'H:i:s' ) );
		}
		$lock_expire = $this->maybe_set_lock_expiration( $queue, $lock_expire );
		if ( $bAltitude ) {
			$lock_expire = $this->interpolateMissingData( $missing_altitude_keys, $this->data_mesgs['record']['altitude'], false, $paused_timestamps, $queue, $lock_expire );
			// $this->logger->debug( 'phpFITFileAnalysis->fixData(): finished adding missing altitude data at ' . gmdate( 'H:i:s' ) );
		}
		$lock_expire = $this->maybe_set_lock_expiration( $queue, $lock_expire );
		if ( $bEnhancedSpeed ) {
			$lock_expire = $this->interpolateMissingData( $missing_enhanced_speed_keys, $this->data_mesgs['record']['enhanced_speed'], false, $paused_timestamps, $queue, $lock_expire );
			// $this->logger->debug( 'phpFITFileAnalysis->fixData(): finished adding missing enhanced speed data at ' . gmdate( 'H:i:s' ) );
		}
		$lock_expire = $this->maybe_set_lock_expiration( $queue, $lock_expire );
		if ( $bEnhancedAltitude ) {
			$lock_expire = $this->interpolateMissingData( $missing_enhanced_altitude_keys, $this->data_mesgs['record']['enhanced_altitude'], false, $paused_timestamps, $queue, $lock_expire );
			// $this->logger->debug( 'phpFITFileAnalysis->fixData(): finished adding missing enhanced altitude data at ' . gmdate( 'H:i:s' ) );
		}
		$lock_expire = $this->maybe_set_lock_expiration( $queue, $lock_expire );
	}

	/**
	 * Does mandatory fixes.
	 * Does not yet identify the missing keys for that data.
	 */
	private function fixDataSingle( $mesgs ) {
		// By default the constant FIT_UNIX_TS_DIFF will be added to timestamps, which have field type of date_time (or local_date_time).
		// Timestamp fields (field number == 253) converted after being unpacked in $this->readDataRecords().
		if ( ! $this->garmin_timestamps ) {
			$date_times = array(
				array(
					'message_name' => 'activity',
					'field_name'   => 'local_timestamp',
				),
				array(
					'message_name' => 'course_point',
					'field_name'   => 'timestamp',
				),
				array(
					'message_name' => 'file_id',
					'field_name'   => 'time_created',
				),
				array(
					'message_name' => 'goal',
					'field_name'   => 'end_date',
				),
				array(
					'message_name' => 'goal',
					'field_name'   => 'start_date',
				),
				array(
					'message_name' => 'lap',
					'field_name'   => 'start_time',
				),
				array(
					'message_name' => 'length',
					'field_name'   => 'start_time',
				),
				array(
					'message_name' => 'monitoring',
					'field_name'   => 'local_timestamp',
				),
				array(
					'message_name' => 'monitoring_info',
					'field_name'   => 'local_timestamp',
				),
				array(
					'message_name' => 'obdii_data',
					'field_name'   => 'start_timestamp',
				),
				array(
					'message_name' => 'schedule',
					'field_name'   => 'scheduled_time',
				),
				array(
					'message_name' => 'schedule',
					'field_name'   => 'time_created',
				),
				array(
					'message_name' => 'segment_lap',
					'field_name'   => 'start_time',
				),
				array(
					'message_name' => 'session',
					'field_name'   => 'start_time',
				),
				array(
					'message_name' => 'timestamp_correlation',
					'field_name'   => 'local_timestamp',
				),
				array(
					'message_name' => 'timestamp_correlation',
					'field_name'   => 'system_timestamp',
				),
				array(
					'message_name' => 'training_file',
					'field_name'   => 'time_created',
				),
				array(
					'message_name' => 'video_clip',
					'field_name'   => 'end_timestamp',
				),
				array(
					'message_name' => 'video_clip',
					'field_name'   => 'start_timestamp',
				),
			);

			foreach ( $date_times as $date_time ) {
				if ( isset( $mesgs[ $date_time['message_name'] ][ $date_time['field_name'] ] ) ) {
					if ( is_array( $mesgs[ $date_time['message_name'] ][ $date_time['field_name'] ] ) ) {
						foreach ( $mesgs[ $date_time['message_name'] ][ $date_time['field_name'] ] as &$element ) {
							$element += FIT_UNIX_TS_DIFF;
						}
					} else {
						$mesgs[ $date_time['message_name'] ][ $date_time['field_name'] ] += FIT_UNIX_TS_DIFF;
					}
				}
			}
		}

		// Find messages that have been unpacked as unsigned integers that should be signed integers.
		// http://php.net/manual/en/function.pack.php - signed integers endianness is always machine dependent.
		// 131    s    signed short (always 16 bit, machine byte order)
		// 133    l    signed long (always 32 bit, machine byte order)
		// 142    q    signed long long (always 64 bit, machine byte order)
		foreach ( $this->defn_mesgs_all as $mesg ) {
			if ( isset( $this->data_mesg_info[ $mesg['global_mesg_num'] ] ) ) {
				$mesg_name = $this->data_mesg_info[ $mesg['global_mesg_num'] ]['mesg_name'];

				foreach ( $mesg['field_defns'] as $field ) {
					// Convert uint16 to sint16
					if ( $field['base_type'] === 131 && isset( $this->data_mesg_info[ $mesg['global_mesg_num'] ]['field_defns'][ $field['field_definition_number'] ]['field_name'] ) ) {
						$field_name = $this->data_mesg_info[ $mesg['global_mesg_num'] ]['field_defns'][ $field['field_definition_number'] ]['field_name'];
						if ( isset( $mesgs[ $mesg_name ][ $field_name ] ) ) {
							if ( is_array( $mesgs[ $mesg_name ][ $field_name ] ) ) {
								foreach ( $mesgs[ $mesg_name ][ $field_name ] as &$v ) {
									if ( PHP_INT_SIZE === 8 && $v > 0x7FFF ) {
										$v -= 0x10000;
									}
									if ( $v > 0x7FFF ) {
										$v = -1 * ( $v - 0x7FFF );
									}
								}
							} elseif ( $mesgs[ $mesg_name ][ $field_name ] > 0x7FFF ) {
								if ( PHP_INT_SIZE === 8 ) {
									$mesgs[ $mesg_name ][ $field_name ] -= 0x10000;
								}
								$mesgs[ $mesg_name ][ $field_name ] = -1 * ( $mesgs[ $mesg_name ][ $field_name ] - 0x7FFF );
							}
						}
					} // Convert uint32 to sint32
					elseif ( $field['base_type'] === 133 && isset( $this->data_mesg_info[ $mesg['global_mesg_num'] ]['field_defns'][ $field['field_definition_number'] ]['field_name'] ) ) {
						$field_name = $this->data_mesg_info[ $mesg['global_mesg_num'] ]['field_defns'][ $field['field_definition_number'] ]['field_name'];
						if ( isset( $mesgs[ $mesg_name ][ $field_name ] ) ) {
							if ( is_array( $mesgs[ $mesg_name ][ $field_name ] ) ) {
								foreach ( $mesgs[ $mesg_name ][ $field_name ] as &$v ) {
									if ( PHP_INT_SIZE === 8 && $v > 0x7FFFFFFF ) {
										$v -= 0x100000000;
									}
									if ( $v > 0x7FFFFFFF ) {
										$v = -1 * ( $v - 0x7FFFFFFF );
									}
								}
							} elseif ( $mesgs[ $mesg_name ][ $field_name ] > 0x7FFFFFFF ) {
								if ( PHP_INT_SIZE === 8 ) {
									$mesgs[ $mesg_name ][ $field_name ] -= 0x100000000;

								}
								if ( $mesgs[ $mesg_name ][ $field_name ] > 0x7FFFFFFF ) {
									$mesgs[ $mesg_name ][ $field_name ] = -1 * ( $mesgs[ $mesg_name ][ $field_name ] - 0x7FFFFFFF );
								}
							}
						}
					} // Convert uint64 to sint64
					elseif ( $field['base_type'] === 142 && isset( $this->data_mesg_info[ $mesg['global_mesg_num'] ]['field_defns'][ $field['field_definition_number'] ]['field_name'] ) ) {
						$field_name = $this->data_mesg_info[ $mesg['global_mesg_num'] ]['field_defns'][ $field['field_definition_number'] ]['field_name'];
						if ( isset( $mesgs[ $mesg_name ][ $field_name ] ) ) {
							if ( is_array( $mesgs[ $mesg_name ][ $field_name ] ) ) {
								foreach ( $mesgs[ $mesg_name ][ $field_name ] as &$v ) {
									if ( PHP_INT_SIZE === 8 && $v > 0x7FFFFFFFFFFFFFFF ) {
										$v -= 0x10000000000000000;
									}
									if ( $v > 0x7FFFFFFFFFFFFFFF ) {
										$v = -1 * ( $v - 0x7FFFFFFFFFFFFFFF );
									}
								}
							} elseif ( $mesgs[ $mesg_name ][ $field_name ] > 0x7FFFFFFFFFFFFFFF ) {
								if ( PHP_INT_SIZE === 8 ) {
									$mesgs[ $mesg_name ][ $field_name ] -= 0x10000000000000000;
								}
								$mesgs[ $mesg_name ][ $field_name ] = -1 * ( $mesgs[ $mesg_name ][ $field_name ] - 0x7FFFFFFFFFFFFFFF );
							}
						}
					}
				}
			}
		}

		return $mesgs;
	}

	private function filterPauseGapThreshold( &$paused_timestamps ) {
		$gap_threshold_seconds = 60;
		$i                     = 0;
		$checked_timestamps    = array();

		foreach ( $paused_timestamps as $timestamp => $is_paused ) {
			if ( in_array( $timestamp, $checked_timestamps, true ) ) {
				++$i;
				continue;
			}

			if ( ! $is_paused ) {
				$checked_timestamps[] = $timestamp;
				++$i;

				continue;
			}

			// look ahead to when was unpaused at
			$unpaused_at = array_search( false, array_slice( $paused_timestamps, $i, null, true ) );

			if ( $unpaused_at - $timestamp <= $gap_threshold_seconds ) {
				for ( $x = $timestamp; $x < $unpaused_at; ++$x ) {
					$paused_timestamps[ $x ] = false;
					$checked_timestamps[]    = $x;
				}
			} else {
				$checked_timestamps = array_merge( $checked_timestamps, range( $timestamp, $unpaused_at ) );
			}

			++$i;
		}
	}

	/**
	 * For the missing keys in the data, interpolate using values either side and insert as necessary.
	 *
	 * @return int|null The lock expiration time.
	 */
	private function interpolateMissingData( &$missing_keys, &$array, $is_int, $paused_timestamps, $queue = null, $lock_expire = null ) {
		$lock_expire = $this->get_lock_expiration( $queue );

		if ( ! is_array( $array ) ) {
			return;  // Can't interpolate if not an array
		}

		$num_points = 2;

		$min_key = min( array_keys( $array ) );
		$max_key = max( array_keys( $array ) );
		$count   = count( $missing_keys );

		for ( $i = 0; $i < $count; ++$i ) {
			$lock_expire = $this->maybe_set_lock_expiration( $queue, $lock_expire );

			$missing_timestamp = $missing_keys[ $i ];

			if ( $missing_timestamp !== 0 ) {
				$is_paused_timestamp = isset( $paused_timestamps[ $missing_timestamp ] ) && $paused_timestamps[ $missing_timestamp ] === true;

				// Interpolating outside recorded range is impossible - use edge values instead
				if ( $missing_timestamp > $max_key ) {
					$array[ $missing_timestamp ] = $is_paused_timestamp ? null : $array[ $max_key ];
					continue;
				} elseif ( $missing_timestamp < $min_key ) {
					$array[ $missing_timestamp ] = $is_paused_timestamp ? null : $array[ $min_key ];
					continue;
				}

				$prev_value = $next_value = reset( $array );

				while ( $missing_timestamp > key( $array ) ) {
					$prev_value = current( $array );
					$next_value = next( $array );
				}
				for ( $j = $i + 1; $j < $count; ++$j ) {
					if ( $missing_keys[ $j ] < key( $array ) ) {
						++$num_points;
					} else {
						break;
					}
				}

				$gap = ( $next_value - $prev_value ) / $num_points;

				for ( $k = 0; $k <= $num_points - 2; ++$k ) {
					$gap_value = null;

					if ( ! $is_paused_timestamp ) {
						if ( $is_int ) {
							$gap_value = (int) round( $prev_value + ( $gap * ( $k + 1 ) ) );
						} else {
							$gap_value = $prev_value + ( $gap * ( $k + 1 ) );
						}
					}

					$array[ $missing_keys[ $i + $k ] ] = $gap_value;
				}
				for ( $k = 0; $k <= $num_points - 2; ++$k ) {
					$missing_keys[ $i + $k ] = 0;
				}

				$num_points = 2;
			}
		}

		ksort( $array );  // sort using keys

		return $lock_expire;
	}

	/**
	 * Change arrays that contain only one element into non-arrays so you can use $variable rather than $variable[0] to access.
	 */
	private function oneElementArrays() {
		foreach ( $this->data_mesgs as $mesg_key => $mesg ) {
			if ( $mesg_key === 'developer_data' ) {
				continue;
			}
			foreach ( $mesg as $field_key => $field ) {
				if ( count( $field ) === 1 ) {
					$first_key                                   = key( $field );
					$this->data_mesgs[ $mesg_key ][ $field_key ] = $field[ $first_key ];
				}
			}
		}
	}

	/**
	 * Change arrays that contain only one element into non-arrays so you can use $variable rather than $variable[0] to access.
	 */
	private function oneElementArraysSingle( $mesgs ) {
		// Expect only one $mesg at a time.  Record messages are already clean.
		foreach ( $mesgs as $mesg_key => $mesg ) {
			if ( 'developer_data' === $mesg_key ) {
				continue;
			}
			foreach ( $mesg as $field_key => $field ) {
				if ( is_array( $field ) && count( $field ) === 1 ) {
					$first_key                        = key( $field );
					$mesgs[ $mesg_key ][ $field_key ] = $field[ $first_key ];
				}
			}
		}
		return $mesgs;
	}

	/**
	 * The FIT protocol makes use of enumerated data types.
	 * Where these values have been identified in the FIT SDK, they have been included in $this->enum_data
	 * This function returns the enumerated value for a given message type.
	 */
	public function enumData( $type, $value ) {
		if ( is_array( $value ) ) {
			$tmp = array();
			foreach ( $value as $element ) {
				if ( isset( $this->enum_data[ $type ][ $element ] ) ) {
					$tmp[] = $this->enum_data[ $type ][ $element ];
				} else {
					$tmp[] = 'unknown';
				}
			}
			return $tmp;
		} else {
			return isset( $this->enum_data[ $type ][ $value ] ) ? $this->enum_data[ $type ][ $value ] : 'unknown';
		}
	}

	/**
	 * Short-hand access to commonly used enumerated data.
	 */
	public function manufacturer() {
		$tmp = $this->enumData( 'manufacturer', $this->data_mesgs['device_info']['manufacturer'] );
		return is_array( $tmp ) ? $tmp[0] : $tmp;
	}
	public function product() {
		$tmp = $this->enumData( 'product', $this->data_mesgs['device_info']['product'] );
		return is_array( $tmp ) ? $tmp[0] : $tmp;
	}
	public function sport( int $index = null ) {
		$tmp = $this->enumData( 'sport', $this->data_mesgs['session']['sport'] ?? ( $this->data_mesgs['sport']['sport'] ?? 0 ) );

		if ( is_array( $tmp ) ) {
			if ( $index !== null ) {
				if ( isset( $tmp[ $index ] ) ) {
					return $tmp[ $index ];
				}
			}

			return $tmp[0];
		}

		return $tmp;
	}

	/**
	 * Transform the values read from the FIT file into the units requested by the user.
	 */
	private function setUnits( $options ) {
		if ( ! empty( $options['units'] ) ) {
			// Handle $options['units'] not being passed as array and/or not in lowercase.
			$units = strtolower( ( is_array( $options['units'] ) ) ? $options['units'][0] : $options['units'] );
		} else {
			$units = 'metric';
		}

		// Handle $options['pace'] being pass as array and/or boolean vs string and/or lowercase.
		$bPace = false;
		if ( isset( $options['pace'] ) ) {
			$pace = is_array( $options['pace'] ) ? $options['pace'][0] : $options['pace'];
			if ( is_bool( $pace ) ) {
				$bPace = $pace;
			} elseif ( is_string( $pace ) ) {
				$pace = strtolower( $pace );
				if ( $pace === 'true' || $pace === 'false' ) {
					$bPace = $pace;
				} else {
					throw new \Exception( 'phpFITFileAnalysis->setUnits(): pace option not valid!' );
				}
			} else {
				throw new \Exception( 'phpFITFileAnalysis->setUnits(): pace option not valid!' );
			}
		}

		// Set units for all messages
		$messages    = array( 'session', 'lap', 'record', 'segment_lap' );
		$c_fields    = array(
			'avg_temperature',
			'max_temperature',
			'temperature',
		);
		$m_fields    = array(
			'distance',
			'total_distance',
		);
		$m_ft_fields = array(
			'altitude',
			'avg_altitude',
			'enhanced_avg_altitude',
			'enhanced_max_altitude',
			'enhanced_min_altitude',
			'max_altitude',
			'min_altitude',
			'total_ascent',
			'total_descent',
		);
		$ms_fields   = array(
			'avg_neg_vertical_speed',
			'avg_pos_vertical_speed',
			'avg_speed',
			'enhanced_avg_speed',
			'enhanced_max_speed',
			'enhanced_speed',
			'max_neg_vertical_speed',
			'max_pos_vertical_speed',
			'max_speed',
			'speed',
			'vertical_speed',
		);
		$semi_fields = array(
			'end_position_lat',
			'end_position_long',
			'nec_lat',
			'nec_long',
			'position_lat',
			'position_long',
			'start_position_lat',
			'start_position_long',
			'swc_lat',
			'swc_long',
		);

		foreach ( $messages as $message ) {
			switch ( $units ) {
				case 'statute':
					// convert from celsius to fahrenheit
					foreach ( $c_fields as $field ) {
						if ( isset( $this->data_mesgs[ $message ][ $field ] ) ) {
							if ( is_array( $this->data_mesgs[ $message ][ $field ] ) ) {
								foreach ( $this->data_mesgs[ $message ][ $field ] as &$value ) {
									$value = round( ( ( $value * 9 ) / 5 ) + 32, 2 );
								}
							} else {
								$this->data_mesgs[ $message ][ $field ] = round( ( ( $this->data_mesgs[ $message ][ $field ] * 9 ) / 5 ) + 32, 2 );
							}
						}
					}

					// convert from meters to miles
					foreach ( $m_fields as $field ) {
						if ( isset( $this->data_mesgs[ $message ][ $field ] ) ) {
							if ( is_array( $this->data_mesgs[ $message ][ $field ] ) ) {
								foreach ( $this->data_mesgs[ $message ][ $field ] as &$value ) {
									$value = round( $value * 0.000621371192, 5 );  // JKK: increased from 2 to 5 decimals.
								}
							} else {
								$this->data_mesgs[ $message ][ $field ] = round( $this->data_mesgs[ $message ][ $field ] * 0.000621371192, 5 );  // JKK: increased from 2 to 4 decimals.
							}
						}
					}

					// convert from meters to feet
					foreach ( $m_ft_fields as $field ) {
						if ( isset( $this->data_mesgs[ $message ][ $field ] ) ) {
							if ( is_array( $this->data_mesgs[ $message ][ $field ] ) ) {
								foreach ( $this->data_mesgs[ $message ][ $field ] as &$value ) {
									$value = round( $value * 3.2808399, 1 );
								}
							} else {
								$this->data_mesgs[ $message ][ $field ] = round( $this->data_mesgs[ $message ][ $field ] * 3.2808399, 1 );
							}
						}
					}

					// convert  meters per second to miles per hour
					foreach ( $ms_fields as $field ) {
						if ( isset( $this->data_mesgs[ $message ][ $field ] ) ) {
							if ( is_array( $this->data_mesgs[ $message ][ $field ] ) ) {
								foreach ( $this->data_mesgs[ $message ][ $field ] as &$value ) {
									if ( $bPace ) {
										$value = round( 60 / 2.23693629 / $value, 3 );
									} else {
										$value = round( $value * 2.23693629, 3 );
									}
								}
							} elseif ( $bPace ) {
									$this->data_mesgs[ $message ][ $field ] = round( 60 / 2.23693629 / $this->data_mesgs[ $message ][ $field ], 3 );
							} else {
								$this->data_mesgs[ $message ][ $field ] = round( $this->data_mesgs[ $message ][ $field ] * 2.23693629, 3 );
							}
						}
					}

					// convert from semicircles to degress
					foreach ( $semi_fields as $field ) {
						if ( isset( $this->data_mesgs[ $message ][ $field ] ) ) {
							if ( is_array( $this->data_mesgs[ $message ][ $field ] ) ) {
								foreach ( $this->data_mesgs[ $message ][ $field ] as &$value ) {
									$value = round( $value * ( 180.0 / pow( 2, 31 ) ), 5 );
								}
							} else {
								$this->data_mesgs[ $message ][ $field ] = round( $this->data_mesgs[ $message ][ $field ] * ( 180.0 / pow( 2, 31 ) ), 5 );
							}
						}
					}

					break;

				case 'raw':
					// Do nothing - leave values as read from file.
					break;
				case 'metric':
					// convert from meters to kilometers
					foreach ( $m_fields as $field ) {
						if ( isset( $this->data_mesgs[ $message ][ $field ] ) ) {
							if ( is_array( $this->data_mesgs[ $message ][ $field ] ) ) {
								foreach ( $this->data_mesgs[ $message ][ $field ] as &$value ) {
									$value = round( $value * 0.001, 4 );  // JKK: increased from 2 to 4 decimals.
								}
							} else {
								$this->data_mesgs[ $message ][ $field ] = round( $this->data_mesgs[ $message ][ $field ] * 0.001, 4 );  // JKK: increased from 2 to 4 decimals.
							}
						}
					}

					// convert  meters per second to kilometers per hour
					foreach ( $ms_fields as $field ) {
						if ( isset( $this->data_mesgs[ $message ][ $field ] ) ) {
							if ( is_array( $this->data_mesgs[ $message ][ $field ] ) ) {
								foreach ( $this->data_mesgs[ $message ][ $field ] as &$value ) {
									if ( $bPace ) {
										$value = ( $value != 0 ) ? round( 60 / 3.6 / $value, 3 ) : 0;
									} else {
										$value = round( $value * 3.6, 3 );
									}
								}
							} else {
								if ( $this->data_mesgs[ $message ][ $field ] === 0 ) {  // Prevent divide by zero error
									continue;
								}
								if ( $bPace ) {
									$this->data_mesgs[ $message ][ $field ] = round( 60 / 3.6 / $this->data_mesgs[ $message ][ $field ], 3 );
								} else {
									$this->data_mesgs[ $message ][ $field ] = round( $this->data_mesgs[ $message ][ $field ] * 3.6, 3 );
								}
							}
						}
					}

					// convert from semicircles to degress
					foreach ( $semi_fields as $field ) {
						if ( isset( $this->data_mesgs[ $message ][ $field ] ) ) {
							if ( is_array( $this->data_mesgs[ $message ][ $field ] ) ) {
								foreach ( $this->data_mesgs[ $message ][ $field ] as &$value ) {
									$value = round( $value * ( 180.0 / pow( 2, 31 ) ), 5 );
								}
							} else {
								$this->data_mesgs[ $message ][ $field ] = round( $this->data_mesgs[ $message ][ $field ] * ( 180.0 / pow( 2, 31 ) ), 5 );
							}
						}
					}

					break;
				default:
					throw new \Exception( 'phpFITFileAnalysis->setUnits(): units option not valid!' );
					break;
			}
		}
	}

	/**
	 * Transform the values read from the FIT file into the units requested by the user.
	 */
	private function setUnitsSingle( $mesgs ) {
		if ( ! empty( $this->options['units'] ) ) {
			// Handle $this->options['units'] not being passed as array and/or not in lowercase.
			$units = strtolower( ( is_array( $this->options['units'] ) ) ? $this->options['units'][0] : $this->options['units'] );
		} else {
			$units = 'metric';
		}

		// Handle $this->options['pace'] being pass as array and/or boolean vs string and/or lowercase.
		$bPace = false;
		if ( isset( $this->options['pace'] ) ) {
			$pace = is_array( $this->options['pace'] ) ? $this->options['pace'][0] : $this->options['pace'];
			if ( is_bool( $pace ) ) {
				$bPace = $pace;
			} elseif ( is_string( $pace ) ) {
				$pace = strtolower( $pace );
				if ( $pace === 'true' || $pace === 'false' ) {
					$bPace = $pace;
				} else {
					throw new \Exception( 'phpFITFileAnalysis->setUnits(): pace option not valid!' );
				}
			} else {
				throw new \Exception( 'phpFITFileAnalysis->setUnits(): pace option not valid!' );
			}
		}

		// Set units for all messages
		$messages    = array( 'session', 'lap', 'record', 'segment_lap' );
		$c_fields    = array(
			'avg_temperature',
			'max_temperature',
			'temperature',
		);
		$m_fields    = array(
			'distance',
			'total_distance',
		);
		$m_ft_fields = array(
			'altitude',
			'avg_altitude',
			'enhanced_avg_altitude',
			'enhanced_max_altitude',
			'enhanced_min_altitude',
			'max_altitude',
			'min_altitude',
			'total_ascent',
			'total_descent',
		);
		$ms_fields   = array(
			'avg_neg_vertical_speed',
			'avg_pos_vertical_speed',
			'avg_speed',
			'enhanced_avg_speed',
			'enhanced_max_speed',
			'enhanced_speed',
			'max_neg_vertical_speed',
			'max_pos_vertical_speed',
			'max_speed',
			'speed',
			'vertical_speed',
		);
		$semi_fields = array(
			'end_position_lat',
			'end_position_long',
			'nec_lat',
			'nec_long',
			'position_lat',
			'position_long',
			'start_position_lat',
			'start_position_long',
			'swc_lat',
			'swc_long',
		);

		foreach ( $messages as $message ) {
			switch ( $units ) {
				case 'statute':
					// convert from celsius to fahrenheit
					foreach ( $c_fields as $field ) {
						if ( isset( $mesgs[ $message ][ $field ] ) ) {
							if ( is_array( $mesgs[ $message ][ $field ] ) ) {
								foreach ( $mesgs[ $message ][ $field ] as &$value ) {
									$value = round( ( ( $value * 9 ) / 5 ) + 32, 2 );
								}
							} else {
								$mesgs[ $message ][ $field ] = round( ( ( $mesgs[ $message ][ $field ] * 9 ) / 5 ) + 32, 2 );
							}
						}
					}

					// convert from meters to miles
					foreach ( $m_fields as $field ) {
						if ( isset( $mesgs[ $message ][ $field ] ) ) {
							if ( is_array( $mesgs[ $message ][ $field ] ) ) {
								foreach ( $mesgs[ $message ][ $field ] as &$value ) {
									$value = round( $value * 0.000621371192, 5 );  // JKK: increased from 2 to 5 decimals.
								}
							} else {
								$mesgs[ $message ][ $field ] = round( $mesgs[ $message ][ $field ] * 0.000621371192, 5 );  // JKK: increased from 2 to 4 decimals.
							}
						}
					}

					// convert from meters to feet
					foreach ( $m_ft_fields as $field ) {
						if ( isset( $mesgs[ $message ][ $field ] ) ) {
							if ( is_array( $mesgs[ $message ][ $field ] ) ) {
								foreach ( $mesgs[ $message ][ $field ] as &$value ) {
									$value = round( $value * 3.2808399, 1 );
								}
							} else {
								$mesgs[ $message ][ $field ] = round( $mesgs[ $message ][ $field ] * 3.2808399, 1 );
							}
						}
					}

					// convert  meters per second to miles per hour
					foreach ( $ms_fields as $field ) {
						if ( isset( $mesgs[ $message ][ $field ] ) ) {
							if ( is_array( $mesgs[ $message ][ $field ] ) ) {
								foreach ( $mesgs[ $message ][ $field ] as &$value ) {
									if ( $bPace ) {
										$value = round( 60 / 2.23693629 / $value, 3 );
									} else {
										$value = round( $value * 2.23693629, 3 );
									}
								}
							} elseif ( $bPace ) {
									$mesgs[ $message ][ $field ] = round( 60 / 2.23693629 / $mesgs[ $message ][ $field ], 3 );
							} else {
								$mesgs[ $message ][ $field ] = round( $mesgs[ $message ][ $field ] * 2.23693629, 3 );
							}
						}
					}

					// convert from semicircles to degress
					foreach ( $semi_fields as $field ) {
						if ( isset( $mesgs[ $message ][ $field ] ) ) {
							if ( is_array( $mesgs[ $message ][ $field ] ) ) {
								foreach ( $mesgs[ $message ][ $field ] as &$value ) {
									$value = round( $value * ( 180.0 / pow( 2, 31 ) ), 5 );
								}
							} else {
								$mesgs[ $message ][ $field ] = round( $mesgs[ $message ][ $field ] * ( 180.0 / pow( 2, 31 ) ), 5 );
							}
						}
					}

					break;

				case 'raw':
					// Do nothing - leave values as read from file.
					break;
				case 'metric':
					// convert from meters to kilometers
					foreach ( $m_fields as $field ) {
						if ( isset( $mesgs[ $message ][ $field ] ) ) {
							if ( is_array( $mesgs[ $message ][ $field ] ) ) {
								foreach ( $mesgs[ $message ][ $field ] as &$value ) {
									$value = round( $value * 0.001, 4 );  // JKK: increased from 2 to 4 decimals.
								}
							} else {
								$mesgs[ $message ][ $field ] = round( $mesgs[ $message ][ $field ] * 0.001, 4 );  // JKK: increased from 2 to 4 decimals.
							}
						}
					}

					// convert  meters per second to kilometers per hour
					foreach ( $ms_fields as $field ) {
						if ( isset( $mesgs[ $message ][ $field ] ) ) {
							if ( is_array( $mesgs[ $message ][ $field ] ) ) {
								foreach ( $mesgs[ $message ][ $field ] as &$value ) {
									if ( $bPace ) {
										$value = ( $value != 0 ) ? round( 60 / 3.6 / $value, 3 ) : 0;
									} else {
										$value = round( $value * 3.6, 3 );
									}
								}
							} else {
								if ( $mesgs[ $message ][ $field ] === 0 ) {  // Prevent divide by zero error
									continue;
								}
								if ( $bPace ) {
									$mesgs[ $message ][ $field ] = round( 60 / 3.6 / $mesgs[ $message ][ $field ], 3 );
								} else {
									$mesgs[ $message ][ $field ] = round( $mesgs[ $message ][ $field ] * 3.6, 3 );
								}
							}
						}
					}

					// convert from semicircles to degress
					foreach ( $semi_fields as $field ) {
						if ( isset( $mesgs[ $message ][ $field ] ) ) {
							if ( is_array( $mesgs[ $message ][ $field ] ) ) {
								foreach ( $mesgs[ $message ][ $field ] as &$value ) {
									$value = round( $value * ( 180.0 / pow( 2, 31 ) ), 5 );
								}
							} else {
								$mesgs[ $message ][ $field ] = round( $mesgs[ $message ][ $field ] * ( 180.0 / pow( 2, 31 ) ), 5 );
							}
						}
					}

					break;
				default:
					throw new \Exception( 'phpFITFileAnalysis->setUnits(): units option not valid!' );
					break;
			}
		}

		return $mesgs;
	}

	/**
	 * Calculate stop points and include them in the record table.
	 *
	 * @param callable $record_callback Callback function which should return 0 or 1 for stop field.
	 * @param object   $queue           Queue object
	 */
	public function calculateStopPoints( callable $record_callback, $queue = null ) {
		if ( ! is_callable( $record_callback ) ) {
			throw new \Exception( 'phpFITFileAnalysis->calculateStopPoints(): record_callback not callable!' );
		}

		if ( isset( $this->options['buffer_input_to_db'] ) && $this->options['buffer_input_to_db'] && $this->checkFileBufferOptions( $this->options['database'] ) ) {
			if ( ! $this->connect_to_db() ) {
				$this->logger->error( 'phpFITFileAnalysis->calculateStopPoints(): unable to connect to database!' );
				throw new \Exception( 'phpFITFileAnalysis->calculateStopPoints: unable to connect to database' );
			} else {
				$this->logger->debug( 'phpFITFileAnalysis->calculateStopPoints(): connected to database: ' . $this->db_name );
			}
		}

		$lock_expire = $this->get_lock_expiration( $queue );

		// Iterate (in batches) through all entries in the record table sorted by timestamp ASC.
		// For each row in the table, call $record_callback and if it returns 1, set the stopped field for that table row to 1.
		$batch_size      = 1000; // Define the batch size for processing.
		$offset          = 0; // Start from the first record.
		$total_processed = 0;
		$last_distance   = 0;
		$dist_delta      = 0;

		$this->create_temp_update_table();
		$this->logger->debug( 'calculateStopPoints: created temp update table' );

		while (true) {
			try {
				$lock_expire = $this->maybe_set_lock_expiration( $queue );

				// Fetch a batch of records sorted by timestamp ASC.
				$query = 'SELECT id, `timestamp`, `distance`, `speed`, `paused`  FROM ' . $this->tables_created['record']['location'] . ' ORDER BY timestamp ASC LIMIT :batch_size OFFSET :offset';
				$stmt  = $this->db->prepare( $query );
				$stmt->bindValue( ':batch_size', $batch_size, \PDO::PARAM_INT );
				$stmt->bindValue( ':offset', $offset, \PDO::PARAM_INT );
				$stmt->execute();

				$records = $stmt->fetchAll( \PDO::FETCH_ASSOC );

				// Break the loop if no more records are found.
				if (empty( $records )) {
					break;
				}

				// Track IDs that need to be updated.
				$ids_to_update_stops = array();
				$placeholders        = array();
				$distance_updates    = array();

				// Iterate through the records and apply the callback.
				foreach ($records as $record) {
					// Look for non-increasing distance values and adjust them.
					$record['distance'] += $dist_delta;
					if ($record['distance'] < $last_distance) {
						$dist_delta         += $last_distance - $record['distance'];
						$record['distance'] += $dist_delta;
					}
					$last_distance = $record['distance'];

					// Add any changed points to the updates arrays.
					if ( $dist_delta > 0 ) {
						$placeholders[]     = '(?,?)';
						$distance_updates[] = $record['id'];
						$distance_updates[] = $record['distance'];
					}

					// Identify stops.
					$stopped = call_user_func( $record_callback, $record );
					if ( 1 === $stopped) {
						$ids_to_update_stops[] = $record['id'];
					}
				}

				if ( ! empty( $distance_updates ) ) {
					$sql  = 'INSERT INTO pffa_temp_update_table (id, new_dist) VALUES ' . implode( ',', $placeholders );
					$stmt = $this->db->prepare( $sql );
					$stmt->execute( $distance_updates );
					$stmt->closeCursor();

					$sql  = 'UPDATE ' . $this->tables_created['record']['location'] . ' r JOIN pffa_temp_update_table t ON r.id = t.id SET r.distance = t.new_dist';
					$stmt = $this->db->prepare( $sql );
					$stmt->execute();
					$stmt->closeCursor();

					$this->truncate_temp_update_table();
				}

				// Update the stopped field for all matching records in one query.
				if (! empty( $ids_to_update_stops ) ) {
					$update_query = 'UPDATE ' . $this->tables_created['record']['location'] . ' SET stopped = 1 WHERE id IN (' . implode( ',', array_map( 'intval', $ids_to_update_stops ) ) . ')';
					$this->db->exec( $update_query );
				}

				$total_processed += count( $records );

				if ($total_processed % 10000 === 0) {
					$this->logger->debug( 'calculateStopPoints: Processed ' . number_format( $total_processed ) . ' records from the database so far' );
				}

				// Increment the offset for the next batch.
				$offset += $batch_size;
			} catch ( \PDOException $e ) {
				// Check if the error is related to a lost connection.
				if (strpos( $e->getMessage(), 'server has gone away' ) !== false || strpos( $e->getMessage(), 'no connection to the server' ) !== false) {
					$this->logger->error( 'Database connection lost. Attempting to reconnect...' );
					try {
						$this->connect_to_db(); // Reconnect to the database.
						$this->logger->info( 'Reconnected to the database successfully.' );
					} catch (\PDOException $reconnectException) {
						$this->logger->error( 'Failed to reconnect to the database: ' . $reconnectException->getMessage() );
						throw $reconnectException; // Exit the loop if reconnection fails.
					}
				} else {
					// Rethrow other exceptions.
					throw $e;
				}
			}
		}

		$this->drop_temp_update_table();

		$this->logger->debug( 'calculateStopPoints: Processed ' . number_format( $total_processed ) . ' records from the database' );
	}

	/**
	 * Create a temporary update table for the record data.
	 */
	private function create_temp_update_table() {
		// Create a temporary table to store the updated records.
		$query = 'CREATE TEMPORARY TABLE IF NOT EXISTS pffa_temp_update_table (id BIGINT UNSIGNED PRIMARY KEY, new_dist DECIMAL(10,5))';
		$this->db->exec( $query );
	}

	/**
	 * Truncate the temporary update table.
	 */
	private function truncate_temp_update_table() {
		// Truncate the temporary table to remove old data.
		$query = 'TRUNCATE TABLE pffa_temp_update_table';
		$this->db->exec( $query );
	}

	/**
	 * Drop the temporary update table.
	 */
	private function drop_temp_update_table() {
		// Drop the temporary table.
		$query = 'DROP TEMPORARY TABLE IF EXISTS pffa_temp_update_table';
		$this->db->exec( $query );
	}

	/**
	 * Calculate HR zones using HRmax formula: zone = HRmax * percentage.
	 */
	public function hrZonesMax( $hr_maximum, $percentages_array = array( 0.60, 0.75, 0.85, 0.95 ) ) {
		if ( array_walk(
			$percentages_array,
			function ( &$value, $key, $hr_maximum ) {
				$value = round( $value * $hr_maximum );
			},
			$hr_maximum
		) ) {
			return $percentages_array;
		} else {
			throw new \Exception( 'phpFITFileAnalysis->hrZonesMax(): cannot calculate zones, please check inputs!' );
		}
	}

	/**
	 * Calculate HR zones using HRreserve formula: zone = HRresting + ((HRmax - HRresting) * percentage).
	 */
	public function hrZonesReserve( $hr_resting, $hr_maximum, $percentages_array = array( 0.60, 0.65, 0.75, 0.82, 0.89, 0.94 ) ) {
		if ( array_walk(
			$percentages_array,
			function ( &$value, $key, $params ) {
				$value = round( $params[0] + ( $value * $params[1] ) );
			},
			array( $hr_resting, $hr_maximum - $hr_resting )
		) ) {
			return $percentages_array;
		} else {
			throw new \Exception( 'phpFITFileAnalysis->hrZonesReserve(): cannot calculate zones, please check inputs!' );
		}
	}

	/**
	 * Calculate power zones using Functional Threshold Power value: zone = FTP * percentage.
	 */
	public function powerZones( $functional_threshold_power, $percentages_array = array( 0.55, 0.75, 0.90, 1.05, 1.20, 1.50 ) ) {
		if ( array_walk(
			$percentages_array,
			function ( &$value, $key, $functional_threshold_power ) {
				$value = round( $value * $functional_threshold_power ) + 1;
			},
			$functional_threshold_power
		) ) {
			return $percentages_array;
		} else {
			throw new \Exception( 'phpFITFileAnalysis->powerZones(): cannot calculate zones, please check inputs!' );
		}
	}

	/**
	 * Partition the data (e.g. cadence, heart_rate, power, speed) using thresholds provided as an array.
	 */
	public function partitionData( $record_field = '', $thresholds = null, $percentages = true, $labels_for_keys = true ) {
		if ( ! isset( $this->data_mesgs['record'][ $record_field ] ) ) {
			throw new \Exception( 'phpFITFileAnalysis->partitionData(): ' . $record_field . ' data not present in FIT file!' );
		}
		if ( ! is_array( $thresholds ) ) {
			throw new \Exception( 'phpFITFileAnalysis->partitionData(): thresholds must be an array e.g. [10,20,30,40,50]!' );
		}

		foreach ( $thresholds as $threshold ) {
			if ( ! is_numeric( $threshold ) || $threshold < 0 ) {
				throw new \Exception( 'phpFITFileAnalysis->partitionData(): ' . $threshold . ' not valid in thresholds!' );
			}
			if ( isset( $last_threshold ) && $last_threshold >= $threshold ) {
				throw new \Exception( 'phpFITFileAnalysis->partitionData(): error near ..., ' . $last_threshold . ', ' . $threshold . ', ... - each element in thresholds array must be greater than previous element!' );
			}
			$last_threshold = $threshold;
		}

		$result = array_fill( 0, count( $thresholds ) + 1, 0 );

		foreach ( $this->data_mesgs['record'][ $record_field ] as $value ) {
			$key   = 0;
			$count = count( $thresholds );
			for ( $key; $key < $count; ++$key ) {
				if ( $value < $thresholds[ $key ] ) {
					break;
				}
			}
			++$result[ $key ];
		}

		array_unshift( $thresholds, 0 );
		$keys = array();

		if ( $labels_for_keys === true ) {
			$count = count( $thresholds );
			for ( $i = 0; $i < $count; ++$i ) {
				$keys[] = $thresholds[ $i ] . ( isset( $thresholds[ $i + 1 ] ) ? '-' . ( $thresholds[ $i + 1 ] - 1 ) : '+' );
			}
			$result = array_combine( $keys, $result );
		}

		if ( $percentages === true ) {
			$total = array_sum( $result );
			array_walk(
				$result,
				function ( &$value, $key, $total ) {
					$value = round( $value / $total * 100, 1 );
				},
				$total
			);
		}

		return $result;
	}

	/**
	 * Split data into buckets/bins using a Counting Sort algorithm (http://en.wikipedia.org/wiki/Counting_sort) to generate data for a histogram plot.
	 */
	public function histogram( $bucket_width = 25, $record_field = '' ) {
		if ( ! isset( $this->data_mesgs['record'][ $record_field ] ) ) {
			throw new \Exception( 'phpFITFileAnalysis->histogram(): ' . $record_field . ' data not present in FIT file!' );
		}
		if ( ! is_numeric( $bucket_width ) || $bucket_width <= 0 ) {
			throw new \Exception( 'phpFITFileAnalysis->histogram(): bucket width is not valid!' );
		}

		foreach ( $this->data_mesgs['record'][ $record_field ] as $value ) {
			$key = round( $value / $bucket_width ) * $bucket_width;
			isset( $result[ $key ] ) ? $result[ $key ]++ : $result[ $key ] = 1;
		}

		for ( $i = 0; $i < max( array_keys( $result ) ) / $bucket_width; ++$i ) {
			if ( ! isset( $result[ $i * $bucket_width ] ) ) {
				$result[ $i * $bucket_width ] = 0;
			}
		}

		ksort( $result );
		return $result;
	}

	/**
	 * Helper functions / shortcuts.
	 */
	public function hrPartionedHRmaximum( $hr_maximum ) {
		return $this->partitionData( 'heart_rate', $this->hrZonesMax( $hr_maximum ) );
	}
	public function hrPartionedHRreserve( $hr_resting, $hr_maximum ) {
		return $this->partitionData( 'heart_rate', $this->hrZonesReserve( $hr_resting, $hr_maximum ) );
	}
	public function powerPartioned( $functional_threshold_power ) {
		return $this->partitionData( 'power', $this->powerZones( $functional_threshold_power ) );
	}
	public function powerHistogram( $bucket_width = 25 ) {
		return $this->histogram( $bucket_width, 'power' );
	}

	/**
	 * Simple moving average algorithm
	 */
	private function sma( $array, $time_period ) {
		$data  = array_values( $array );
		$count = count( $array );

		for ( $i = 0; $i < $count - $time_period; ++$i ) {
			$pieces = array_slice( $data, $i, $time_period );

			// if any of the values are 'null' we want to ignore this chunk since null values indicate pauses at that time
			if ( in_array( null, $pieces, true ) ) {
				continue;
			}

			yield array_sum( $pieces ) / $time_period;
		}
	}

	/**
	 * Calculate TRIMP (TRaining IMPulse) and an Intensity Factor using HR data. Useful if power data not available.
	 * hr_FT is heart rate at Functional Threshold, or Lactate Threshold Heart Rate (LTHR)
	 */
	public function hrMetrics( $hr_resting, $hr_maximum, $hr_FT, $gender ) {
		$hr_metrics = array(  // array to hold HR analysis data
			'TRIMPexp' => 0.0,
			'hrIF'     => 0.0,
		);
		if ( in_array( $gender, array( 'F', 'f', 'Female', 'female' ) ) ) {
			$gender_coeff = 1.67;
		} else {
			$gender_coeff = 1.92;
		}
		foreach ( $this->data_mesgs['record']['heart_rate'] as $hr ) {
			// TRIMPexp formula from http://fellrnr.com/wiki/TRIMP
			// TRIMPexp = sum(D x HRr x 0.64ey)
			$temp_heart_rate         = ( $hr - $hr_resting ) / ( $hr_maximum - $hr_resting );
			$hr_metrics['TRIMPexp'] += ( ( 1 / 60 ) * $temp_heart_rate * 0.64 * ( exp( $gender_coeff * $temp_heart_rate ) ) );
		}
		$hr_metrics['TRIMPexp'] = round( $hr_metrics['TRIMPexp'] );
		$hr_metrics['hrIF']     = round( ( array_sum( $this->data_mesgs['record']['heart_rate'] ) / ( count( $this->data_mesgs['record']['heart_rate'] ) ) ) / $hr_FT, 2 );

		return $hr_metrics;
	}

	/**
	 * Returns 'Average Power', 'Kilojoules', 'Normalised Power', 'Variability Index', 'Intensity Factor', and 'Training Stress Score' in an array.
	 *
	 * Normalised Power (and metrics dependent on it) require the PHP trader extension to be loaded
	 * http://php.net/manual/en/book.trader.php
	 */
	public function powerMetrics( $functional_threshold_power ) {
		if ( ! isset( $this->data_mesgs['record']['power'] ) ) {
			throw new \Exception( 'phpFITFileAnalysis->powerMetrics(): power data not present in FIT file!' );
		}

		$non_null_power_records = array_filter(
			$this->data_mesgs['record']['power'],
			function ( $powerRecord ) {
				return $powerRecord !== null;
			}
		);

		$power_metrics['Average Power'] = array_sum( $non_null_power_records ) / count( $non_null_power_records );
		$power_metrics['Kilojoules']    = ( $power_metrics['Average Power'] * count( $this->data_mesgs['record']['power'] ) ) / 1000;

		// NP1 capture all values for rolling 30s averages
		$NP_values = ( $this->php_trader_ext_loaded ) ? trader_sma( $this->data_mesgs['record']['power'], 30 ) : $this->sma( $this->data_mesgs['record']['power'], 30 );

		$NormalisedPower = 0.0;
		$total_NP_values = 0;
		foreach ( $NP_values as $value ) {  // NP2 Raise all the values obtained in step NP1 to the fourth power
			$NormalisedPower += pow( $value, 4 );
			++$total_NP_values;
		}
		$NormalisedPower                  /= $total_NP_values;  // NP3 Find the average of the values in NP2
		$power_metrics['Normalised Power'] = pow( $NormalisedPower, 1 / 4 );  // NP4 taking the fourth root of the value obtained in step NP3

		$power_metrics['Variability Index']     = $power_metrics['Normalised Power'] / $power_metrics['Average Power'];
		$power_metrics['Intensity Factor']      = $power_metrics['Normalised Power'] / $functional_threshold_power;
		$power_metrics['Training Stress Score'] = ( count( $this->data_mesgs['record']['power'] ) * $power_metrics['Normalised Power'] * $power_metrics['Intensity Factor'] ) / ( $functional_threshold_power * 36 );

		// Round the values to make them something sensible.
		$power_metrics['Average Power']         = (int) round( $power_metrics['Average Power'] );
		$power_metrics['Kilojoules']            = (int) round( $power_metrics['Kilojoules'] );
		$power_metrics['Normalised Power']      = (int) round( $power_metrics['Normalised Power'] );
		$power_metrics['Variability Index']     = round( $power_metrics['Variability Index'], 2 );
		$power_metrics['Intensity Factor']      = round( $power_metrics['Intensity Factor'], 2 );
		$power_metrics['Training Stress Score'] = (int) round( $power_metrics['Training Stress Score'] );

		return $power_metrics;
	}

	/**
	 * Returns Critical Power (Best Efforts) values for supplied time period(s).
	 */
	public function criticalPower( $time_periods ) {
		if ( ! isset( $this->data_mesgs['record']['power'] ) ) {
			throw new \Exception( 'phpFITFileAnalysis->criticalPower(): power data not present in FIT file!' );
		}

		if ( is_array( $time_periods ) ) {
			$count = count( $this->data_mesgs['record']['power'] );
			foreach ( $time_periods as $time_period ) {
				if ( ! is_numeric( $time_period ) ) {
					throw new \Exception( 'phpFITFileAnalysis->criticalPower(): time periods must only contain numeric data!' );
				}
				if ( $time_period < 0 ) {
					throw new \Exception( 'phpFITFileAnalysis->criticalPower(): time periods cannot be negative!' );
				}
				if ( $time_period > $count ) {
					break;
				}

				$averages = ( $this->php_trader_ext_loaded ) ? trader_sma( $this->data_mesgs['record']['power'], $time_period ) : $this->sma( $this->data_mesgs['record']['power'], $time_period );
				if ( $averages !== false ) {
					$criticalPower_values[ $time_period ] = max( $averages );
				}
			}

			return $criticalPower_values;
		} elseif ( is_numeric( $time_periods ) && $time_periods > 0 ) {
			if ( $time_periods > count( $this->data_mesgs['record']['power'] ) ) {
				$criticalPower_values[ $time_periods ] = 0;
			} else {
				$averages = ( $this->php_trader_ext_loaded ) ? trader_sma( $this->data_mesgs['record']['power'], $time_periods ) : $this->sma( $this->data_mesgs['record']['power'], $time_periods );
				if ( $averages !== false ) {
					$criticalPower_values[ $time_periods ] = max( $averages );
				}
			}

			return $criticalPower_values;
		} else {
			throw new \Exception( 'phpFITFileAnalysis->criticalPower(): time periods not valid!' );
		}
	}

	/**
	 * Returns array of booleans using timestamp as key.
	 * true == timer paused (e.g. autopause)
	 */
	public function isPaused() {
		if ( ! isset( $this->data_mesgs['event']['event'] ) || ! is_array( $this->data_mesgs['event']['event'] ) ) {
			return array();
		}

		/**
		 * Event enumerated values of interest
		 * 0 = timer
		 */
		$tek = array_keys( $this->data_mesgs['event']['event'], 0 );  // timer event keys

		$timer_start = array();
		$timer_stop  = array();
		foreach ( $tek as $v ) {
			if ( $this->data_mesgs['event']['event_type'][ $v ] === 0 ) {
				$timer_start[ $v ] = $this->data_mesgs['event']['timestamp'][ $v ];
			} elseif ( $this->data_mesgs['event']['event_type'][ $v ] === 4 ) {
				$timer_stop[ $v ] = $this->data_mesgs['event']['timestamp'][ $v ];
			}
		}

		$first_ts = min( $this->data_mesgs['record']['timestamp'] );  // first timestamp
		$last_ts  = max( $this->data_mesgs['record']['timestamp'] );  // last timestamp

		reset( $timer_start );
		$cur_start = next( $timer_start );
		$cur_stop  = reset( $timer_stop );

		$is_paused = array();
		$bPaused   = false;

		for ( $i = $first_ts; $i < $last_ts; ++$i ) {
			if ( $i == $cur_stop ) {
				$bPaused  = true;
				$cur_stop = next( $timer_stop );
			} elseif ( $i == $cur_start ) {
				$bPaused   = false;
				$cur_start = next( $timer_start );
			}
			$is_paused[ $i ] = $bPaused;
		}
		$is_paused[ $last_ts ] = isset( $this->data_mesgs['record']['speed'] ) && is_array( $this->data_mesgs['record']['speed'] ) && end( $this->data_mesgs['record']['speed'] ) === 0;

		return $is_paused;
	}

	/**
	 * Returns an array that can be used to plot Circumferential Pedal Velocity (x-axis) vs Average Effective Pedal Force (y-axis).
	 * NB Crank length is in metres.
	 */
	public function quadrantAnalysis( $crank_length, $ftp, $selected_cadence = 90, $use_timestamps = false ) {
		if ( $crank_length === null || $ftp === null ) {
			return array();
		}
		if ( empty( $this->data_mesgs['record']['power'] ) || empty( $this->data_mesgs['record']['cadence'] ) ) {
			return array();
		}

		$quadrant_plot                     = array();
		$quadrant_plot['selected_cadence'] = $selected_cadence;
		$quadrant_plot['aepf_threshold']   = round( ( $ftp * 60 ) / ( $selected_cadence * 2 * pi() * $crank_length ), 3 );
		$quadrant_plot['cpv_threshold']    = round( ( $selected_cadence * $crank_length * 2 * pi() ) / 60, 3 );

		// Used to calculate percentage of points in each quadrant
		$quad_percent = array(
			'hf_hv' => 0,
			'hf_lv' => 0,
			'lf_lv' => 0,
			'lf_hv' => 0,
		);

		// Filter zeroes from cadence array (otherwise !div/0 error for AEPF)
		$cadence = array_filter( $this->data_mesgs['record']['cadence'] );
		$cpv     = $aepf = 0.0;

		foreach ( $cadence as $k => $c ) {
			$p = isset( $this->data_mesgs['record']['power'][ $k ] ) ? $this->data_mesgs['record']['power'][ $k ] : 0;

			// Circumferential Pedal Velocity (CPV, m/s) = (Cadence × Crank Length × 2 × Pi) / 60
			$cpv = round( ( $c * $crank_length * 2 * pi() ) / 60, 3 );

			// Average Effective Pedal Force (AEPF, N) = (Power × 60) / (Cadence × 2 × Pi × Crank Length)
			$aepf = round( ( $p * 60 ) / ( $c * 2 * pi() * $crank_length ), 3 );

			if ( $use_timestamps === true ) {
				$quadrant_plot['plot'][ $k ] = array( $cpv, $aepf );
			} else {
				$quadrant_plot['plot'][] = array( $cpv, $aepf );
			}

			if ( $aepf > $quadrant_plot['aepf_threshold'] ) {  // high force
				if ( $cpv > $quadrant_plot['cpv_threshold'] ) {  // high velocity
					++$quad_percent['hf_hv'];
				} else {
					++$quad_percent['hf_lv'];
				}
			} elseif ( $cpv > $quadrant_plot['cpv_threshold'] ) {  // low force
				// high velocity
					++$quad_percent['lf_hv'];
			} else {
				++$quad_percent['lf_lv'];
			}
		}

		// Convert to percentages and add to array that will be returned by the function
		$sum = array_sum( $quad_percent );
		foreach ( $quad_percent as $k => $v ) {
			$quad_percent[ $k ] = round( $v / $sum * 100, 2 );
		}
		$quadrant_plot['quad_percent'] = $quad_percent;

		// Calculate CPV and AEPF for cadences between 20 and 150rpm at and near to FTP
		for ( $c = 20; $c <= 150; $c += 5 ) {
			$cpv                        = round( ( ( $c * $crank_length * 2 * pi() ) / 60 ), 3 );
			$quadrant_plot['ftp-25w'][] = array( $cpv, round( ( ( $ftp - 25 ) * 60 ) / ( $c * 2 * pi() * $crank_length ), 3 ) );
			$quadrant_plot['ftp'][]     = array( $cpv, round( ( $ftp * 60 ) / ( $c * 2 * pi() * $crank_length ), 3 ) );
			$quadrant_plot['ftp+25w'][] = array( $cpv, round( ( ( $ftp + 25 ) * 60 ) / ( $c * 2 * pi() * $crank_length ), 3 ) );
		}

		return $quadrant_plot;
	}

	/**
	 * Returns array of gear change information.
	 */
	public function gearChanges( $bIgnoreTimerPaused = true ) {
		/**
		 * Event enumerated values of interest
		 * 42 = front_gear_change
		 * 43 = rear_gear_change
		 */
		$fgcek = array_keys( $this->data_mesgs['event']['event'], 42 );  // front gear change event keys
		$rgcek = array_keys( $this->data_mesgs['event']['event'], 43 );  // rear gear change event keys

		/**
		 * gear_change_data (uint32)
		 * components:
		 *     rear_gear_num  00000000 00000000 00000000 11111111
		 *     rear_gear      00000000 00000000 11111111 00000000
		 *     front_gear_num 00000000 11111111 00000000 00000000
		 *     front_gear     11111111 00000000 00000000 00000000
		 * scale: 1, 1, 1, 1
		 * bits: 8, 8, 8, 8
		 */

		$fgc         = array();  // front gear components
		$front_gears = array();
		foreach ( $fgcek as $k ) {
			$fgc_tmp = array(
				'timestamp'      => $this->data_mesgs['event']['timestamp'][ $k ],
				// 'data'        => $this->data_mesgs['event']['data'][$k],
				// 'event_type'  => $this->data_mesgs['event']['event_type'][$k],
				// 'event_group' => $this->data_mesgs['event']['event_group'][$k],
				'rear_gear_num'  => $this->data_mesgs['event']['data'][ $k ] & 255,
				'rear_gear'      => ( $this->data_mesgs['event']['data'][ $k ] >> 8 ) & 255,
				'front_gear_num' => ( $this->data_mesgs['event']['data'][ $k ] >> 16 ) & 255,
				'front_gear'     => ( $this->data_mesgs['event']['data'][ $k ] >> 24 ) & 255,
			);

			$fgc[] = $fgc_tmp;

			if ( ! array_key_exists( $fgc_tmp['front_gear_num'], $front_gears ) ) {
				$front_gears[ $fgc_tmp['front_gear_num'] ] = $fgc_tmp['front_gear'];
			}
		}
		ksort( $front_gears );

		$rgc        = array();  // rear gear components
		$rear_gears = array();
		foreach ( $rgcek as $k ) {
			$rgc_tmp = array(
				'timestamp'      => $this->data_mesgs['event']['timestamp'][ $k ],
				// 'data'        => $this->data_mesgs['event']['data'][$k],
				// 'event_type'  => $this->data_mesgs['event']['event_type'][$k],
				// 'event_group' => $this->data_mesgs['event']['event_group'][$k],
				'rear_gear_num'  => $this->data_mesgs['event']['data'][ $k ] & 255,
				'rear_gear'      => ( $this->data_mesgs['event']['data'][ $k ] >> 8 ) & 255,
				'front_gear_num' => ( $this->data_mesgs['event']['data'][ $k ] >> 16 ) & 255,
				'front_gear'     => ( $this->data_mesgs['event']['data'][ $k ] >> 24 ) & 255,
			);

			$rgc[] = $rgc_tmp;

			if ( ! array_key_exists( $rgc_tmp['rear_gear_num'], $rear_gears ) ) {
				$rear_gears[ $rgc_tmp['rear_gear_num'] ] = $rgc_tmp['rear_gear'];
			}
		}
		ksort( $rear_gears );

		$timestamps = $this->data_mesgs['record']['timestamp'];
		$first_ts   = min( $timestamps );  // first timestamp
		$last_ts    = max( $timestamps );   // last timestamp

		$fg = 0;  // front gear at start of ride
		$rg = 0;  // rear gear at start of ride

		if ( isset( $fgc[0]['timestamp'] ) ) {
			if ( $first_ts == $fgc[0]['timestamp'] ) {
				$fg = $fgc[0]['front_gear'];
			} else {
				$fg = $fgc[0]['front_gear_num'] == 1 ? $front_gears[2] : $front_gears[1];
			}
		}

		if ( isset( $rgc[0]['timestamp'] ) ) {
			if ( $first_ts == $rgc[0]['timestamp'] ) {
				$rg = $rgc[0]['rear_gear'];
			} else {
				$rg = $rgc[0]['rear_gear_num'] == min( $rear_gears ) ? $rear_gears[ $rgc[0]['rear_gear_num'] + 1 ] : $rear_gears[ $rgc[0]['rear_gear_num'] - 1 ];
			}
		}

		$fg_summary  = array();
		$rg_summary  = array();
		$combined    = array();
		$gears_array = array();

		if ( $bIgnoreTimerPaused === true ) {
			$is_paused = $this->isPaused();
		}

		reset( $fgc );
		reset( $rgc );
		for ( $i = $first_ts; $i < $last_ts; ++$i ) {
			if ( $bIgnoreTimerPaused === true && $is_paused[ $i ] === true ) {
				continue;
			}

			$fgc_tmp = current( $fgc );
			$rgc_tmp = current( $rgc );

			if ( $i > $fgc_tmp['timestamp'] ) {
				if ( next( $fgc ) !== false ) {
					$fg = $fgc_tmp['front_gear'];
				}
			}
			$fg_summary[ $fg ] = isset( $fg_summary[ $fg ] ) ? $fg_summary[ $fg ] + 1 : 1;

			if ( $i > $rgc_tmp['timestamp'] ) {
				if ( next( $rgc ) !== false ) {
					$rg = $rgc_tmp['rear_gear'];
				}
			}
			$rg_summary[ $rg ] = isset( $rg_summary[ $rg ] ) ? $rg_summary[ $rg ] + 1 : 1;

			$combined[ $fg ][ $rg ] = isset( $combined[ $fg ][ $rg ] ) ? $combined[ $fg ][ $rg ] + 1 : 1;

			$gears_array[ $i ] = array(
				'front_gear' => $fg,
				'rear_gear'  => $rg,
			);
		}

		krsort( $fg_summary );
		krsort( $rg_summary );
		krsort( $combined );

		$output = array(
			'front_gear_summary' => $fg_summary,
			'rear_gear_summary'  => $rg_summary,
			'combined_summary'   => $combined,
			'gears_array'        => $gears_array,
		);

		return $output;
	}

	/**
	 * Create a JSON object that contains available record message information and CPV/AEPF if requested/available.
	 */
	public function getJSON( $crank_length = null, $ftp = null, $data_required = array( 'all' ), $selected_cadence = 90 ) {
		if ( ! is_array( $data_required ) ) {
			$data_required = array( $data_required );
		}
		foreach ( $data_required as &$datum ) {
			$datum = strtolower( $datum );
		}

		$all               = in_array( 'all', $data_required );
		$timestamp         = ( $all || in_array( 'timestamp', $data_required ) );
		$paused            = ( $all || in_array( 'paused', $data_required ) );
		$temperature       = ( $all || in_array( 'temperature', $data_required ) );
		$lap               = ( $all || in_array( 'lap', $data_required ) );
		$position_lat      = ( $all || in_array( 'position_lat', $data_required ) );
		$position_long     = ( $all || in_array( 'position_long', $data_required ) );
		$distance          = ( $all || in_array( 'distance', $data_required ) );
		$altitude          = ( $all || in_array( 'altitude', $data_required ) );
		$speed             = ( $all || in_array( 'speed', $data_required ) );
		$heart_rate        = ( $all || in_array( 'heart_rate', $data_required ) );
		$cadence           = ( $all || in_array( 'cadence', $data_required ) );
		$power             = ( $all || in_array( 'power', $data_required ) );
		$quadrant_analysis = ( $all || in_array( 'quadrant-analysis', $data_required ) );

		$for_json             = array();
		$for_json['fix_data'] = isset( $this->options['fix_data'] ) ? $this->options['fix_data'] : null;
		$for_json['units']    = isset( $this->options['units'] ) ? $this->options['units'] : null;
		$for_json['pace']     = isset( $this->options['pace'] ) ? $this->options['pace'] : null;

		$lap_count = 1;
		$data      = array();
		if ( $quadrant_analysis ) {
			$quadrant_plot = $this->quadrantAnalysis( $crank_length, $ftp, $selected_cadence, true );
			if ( ! empty( $quadrant_plot ) ) {
				$for_json['aepf_threshold'] = $quadrant_plot['aepf_threshold'];
				$for_json['cpv_threshold']  = $quadrant_plot['cpv_threshold'];
			}
		}
		if ( $paused ) {
			$is_paused = $this->isPaused();
		}

		foreach ( $this->data_mesgs['record']['timestamp'] as $ts ) {
			if ( $lap && is_array( $this->data_mesgs['lap']['timestamp'] ) && $ts >= $this->data_mesgs['lap']['timestamp'][ $lap_count - 1 ] ) {
				++$lap_count;
			}
			$tmp = array();
			if ( $timestamp ) {
				$tmp['timestamp'] = $ts;
			}
			if ( $lap ) {
				$tmp['lap'] = $lap_count;
			}

			foreach ( $this->data_mesgs['record'] as $key => $value ) {
				if ( $key !== 'timestamp' ) {
					if ( $$key ) {
						$tmp[ $key ] = isset( $value[ $ts ] ) ? $value[ $ts ] : null;
					}
				}
			}

			if ( $quadrant_analysis ) {
				if ( ! empty( $quadrant_plot ) ) {
					$tmp['cpv']  = isset( $quadrant_plot['plot'][ $ts ] ) ? $quadrant_plot['plot'][ $ts ][0] : null;
					$tmp['aepf'] = isset( $quadrant_plot['plot'][ $ts ] ) ? $quadrant_plot['plot'][ $ts ][1] : null;
				}
			}

			if ( $paused ) {
				$tmp['paused'] = $is_paused[ $ts ];
			}

			$data[] = $tmp;
			unset( $tmp );
		}

		$for_json['data'] = $data;

		return json_encode( $for_json );
	}

	/**
	 * Create a JSON object that contains available lap message information.
	 */
	public function getJSONLap() {
		$for_json             = array();
		$for_json['fix_data'] = isset( $this->options['fix_data'] ) ? $this->options['fix_data'] : null;
		$for_json['units']    = isset( $this->options['units'] ) ? $this->options['units'] : null;
		$for_json['pace']     = isset( $this->options['pace'] ) ? $this->options['pace'] : null;
		$for_json['num_laps'] = count( $this->data_mesgs['lap']['timestamp'] );
		$data                 = array();

		for ( $i = 0; $i < $for_json['num_laps']; $i++ ) {
			$data[ $i ]['lap'] = $i;
			foreach ( $this->data_mesgs['lap'] as $key => $value ) {
				$data[ $i ][ $key ] = $value[ $i ];
			}
		}

		$for_json['data'] = $data;

		return json_encode( $for_json );
	}

	/**
	 * Outputs tables of information being listened for and found within the processed FIT file.
	 */
	public function showDebugInfo() {
		asort( $this->defn_mesgs_all );  // Sort the definition messages

		echo '<h3>Types</h3>';
		echo '<table class=\'table table-condensed table-striped\'>';  // Bootstrap classes
		echo '<thead>';
		echo '<th>key</th>';
		echo '<th>PHP unpack() format</th>';
		echo '<th>Bytes</th>';
		echo '</thead>';
		echo '<tbody>';
		foreach ( $this->types as $key => $val ) {
			echo '<tr><td>' . $key . '</td><td>' . $val['format'] . '</td><td>' . $val['bytes'] . '</td></tr>';
		}
		echo '</tbody>';
		echo '</table>';

		echo '<br><hr><br>';

		echo '<h3>Messages and Fields being listened for</h3>';
		foreach ( $this->data_mesg_info as $key => $val ) {
			echo '<h4>' . $val['mesg_name'] . ' (' . $key . ')</h4>';
			echo '<table class=\'table table-condensed table-striped\'>';
			echo '<thead><th>ID</th><th>Name</th><th>Scale</th><th>Offset</th><th>Units</th></thead><tbody>';
			foreach ( $val['field_defns'] as $key2 => $val2 ) {
				echo '<tr><td>' . $key2 . '</td><td>' . $val2['field_name'] . '</td><td>' . $val2['scale'] . '</td><td>' . $val2['offset'] . '</td><td>' . $val2['units'] . '</td></tr>';
			}
			echo '</tbody></table><br><br>';
		}

		echo '<br><hr><br>';

		echo '<h3>FIT Definition Messages contained within the file</h3>';
		echo '<table class=\'table table-condensed table-striped\'>';
		echo '<thead>';
		echo '<th>global_mesg_num</th>';
		echo '<th>num_fields</th>';
		echo '<th>field defns</th>';
		echo '<th>total_size</th>';
		echo '</thead>';
		echo '<tbody>';
		foreach ( $this->defn_mesgs_all as $key => $val ) {
			echo '<tr><td>' . $val['global_mesg_num'] . ( isset( $this->data_mesg_info[ $val['global_mesg_num'] ] ) ? ' (' . $this->data_mesg_info[ $val['global_mesg_num'] ]['mesg_name'] . ')' : ' (unknown)' ) . '</td><td>' . $val['num_fields'] . '</td><td>';
			foreach ( $val['field_defns'] as $defn ) {
				echo 'defn: ' . $defn['field_definition_number'] . '; size: ' . $defn['size'] . '; type: ' . $defn['base_type'];
				echo ' (' . ( isset( $this->data_mesg_info[ $val['global_mesg_num'] ]['field_defns'][ $defn['field_definition_number'] ] ) ? $this->data_mesg_info[ $val['global_mesg_num'] ]['field_defns'][ $defn['field_definition_number'] ]['field_name'] : 'unknown' ) . ')<br>';
			}
			echo '</td><td>' . $val['total_size'] . '</td></tr>';
		}
		echo '</tbody>';
		echo '</table>';

		echo '<br><hr><br>';

		echo '<h3>Messages found in file</h3>';
		foreach ( $this->data_mesgs as $mesg_key => $mesg ) {
			echo '<table class=\'table table-condensed table-striped\'>';
			echo '<thead><th>' . $mesg_key . '</th><th>count()</th></thead><tbody>';
			foreach ( $mesg as $field_key => $field ) {
				if ( is_array( $field ) ) {
					echo '<tr><td>' . $field_key . '</td><td>' . count( $field ) . '</td></tr>';
				} else {
					echo '<tr><td>' . $field_key . '</td><td>' . $field . '</td></tr>';
				}
			}
			echo '</tbody></table><br><br>';
		}
	}

	/*
	 * Process HR messages
	 *
	 * Based heavily on logic in commit:
	 * https://github.com/GoldenCheetah/GoldenCheetah/commit/957ae470999b9a57b5b8ec57e75512d4baede1ec
	 * Particularly the decodeHr() method
	 *
	 * @param object $queue  Queue object
	 */
	private function processHrMessages( $queue = null ) {
		// Check that we have received HR messages
		if ( empty( $this->data_mesgs['hr'] ) ) {
			return;
		}

		$lock_expire = $this->get_lock_expiration( $queue );

		$hr         = array();
		$timestamps = array();

		// Load all filtered_bpm values into the $hr array
		foreach ( $this->data_mesgs['hr']['filtered_bpm'] as $hr_val ) {
			$lock_expire = $this->maybe_set_lock_expiration( $queue, $lock_expire );

			if ( is_array( $hr_val ) ) {
				foreach ( $hr_val as $sub_hr_val ) {
					$hr[] = $sub_hr_val;
				}
			} else {
				$hr[] = $hr_val;
			}
		}

		// Manually scale timestamps (i.e. divide by 1024)
		$last_event_timestamp = $this->data_mesgs['hr']['event_timestamp'];
		if ( is_array( $last_event_timestamp ) ) {
			$last_event_timestamp = $last_event_timestamp[0];
		}
		$start_timestamp = $this->data_mesgs['hr']['timestamp'] - $last_event_timestamp / 1024.0;
		$timestamps[]    = $last_event_timestamp / 1024.0;

		// Determine timestamps (similar to compressed timestamps)
		foreach ( $this->data_mesgs['hr']['event_timestamp_12'] as $event_timestamp_12_val ) {

			$lock_expire = $this->maybe_set_lock_expiration( $queue, $lock_expire );

			$j = 0;
			for ( $i = 0; $i < 11; $i++ ) {
				$last_event_timestamp12 = $last_event_timestamp & 0xFFF;
				$next_event_timestamp12 = null;

				if ( $j % 2 === 0 ) {
					$next_event_timestamp12 = $event_timestamp_12_val[ $i ] + ( ( $event_timestamp_12_val[ $i + 1 ] & 0xF ) << 8 );
					$last_event_timestamp   = ( $last_event_timestamp & 0xFFFFF000 ) + $next_event_timestamp12;
				} else {
					$next_event_timestamp12 = 16 * $event_timestamp_12_val[ $i + 1 ] + ( ( $event_timestamp_12_val[ $i ] & 0xF0 ) >> 4 );
					$last_event_timestamp   = ( $last_event_timestamp & 0xFFFFF000 ) + $next_event_timestamp12;
					++$i;
				}
				if ( $next_event_timestamp12 < $last_event_timestamp12 ) {
					$last_event_timestamp += 0x1000;
				}

				$timestamps[] = $last_event_timestamp / 1024.0;
				++$j;
			}
		}

		// Map HR values to timestamps
		$filtered_bpm_arr = array();
		$secs             = 0;
		$min_record_ts    = min( $this->data_mesgs['record']['timestamp'] );
		$max_record_ts    = max( $this->data_mesgs['record']['timestamp'] );
		foreach ( $timestamps as $idx => $timestamp ) {

			$lock_expire = $this->maybe_set_lock_expiration( $queue, $lock_expire );

			$ts_secs = round( $timestamp + $start_timestamp );

			// Skip timestamps outside of the range we're interested in
			if ( $ts_secs >= $min_record_ts && $ts_secs <= $max_record_ts ) {
				if ( isset( $filtered_bpm_arr[ $ts_secs ] ) ) {
					$filtered_bpm_arr[ $ts_secs ][0] += $hr[ $idx ];
					++$filtered_bpm_arr[ $ts_secs ][1];
				} else {
					$filtered_bpm_arr[ $ts_secs ] = array( $hr[ $idx ], 1 );
				}
			}
		}

		// Populate the heart_rate fields for record messages
		foreach ( $filtered_bpm_arr as $idx => $arr ) {
			$lock_expire = $this->maybe_set_lock_expiration( $queue, $lock_expire );

			$this->data_mesgs['record']['heart_rate'][ $idx ] = (int) round( $arr[0] / $arr[1] );
			// $this->logger->debug( 'Set heart Rate: ' . $this->data_mesgs['record']['heart_rate'][ $idx ] );
		}
	}

	/**
	 * Get lock expiration.
	 *
	 * @param CCM_GPS_Fit_File_Queue|null $queue Queue for processing FIT file data.
	 *
	 * @return int|bool Lock expiration time.
	 */
	protected function get_lock_expiration( $queue = null ) {
		if ( $queue ) {
			$lock_expire = $queue->get_lock_expiration();
		} else {
			$lock_expire = false;
		}

		return $lock_expire;
	}

	/**
	 * Maybe set lock expiration if within 50% of expiration.
	 *
	 * @param CCM_GPS_Fit_File_Queue|null $queue       Queue for processing FIT file data.
	 */
	protected function maybe_set_lock_expiration( $queue ) {
		if ( $queue ) {
			$queue->maybe_set_lock_expiration();
		}
	}

	/**
	 * Configure the logger.
	 *
	 * @return void
	 */
	public function configure_logger() {
		$this->logger = new Logger( 'pffa' );

		$error_level = $this->get_logging_level();

		// Create a formatter with a custom date format.
		$date_format = 'd-M-Y H:i:s T';
		$output      = "[%datetime%] %channel%.%level_name%: %message% %context% %extra%\n";
		$formatter   = new LineFormatter( $output, $date_format, true, true );

		// Determine the log file path based on the environment.
		$base_dir = $this->trailingslashit( $_ENV['PFFA_HOME'] );
		$log_file = $base_dir . 'debug.log';

		if ( ! $log_file ) {
			error_log( 'pffa: Error log file not found.' );
		} else {
			$stream_handler = new StreamHandler( $log_file, $error_level );
			$stream_handler->setFormatter( $formatter );
			$this->logger->pushHandler( $stream_handler );
		}
	}

	/**
	 * Add a trailing slash to a path if it doesn't already have one.
	 */
	private function trailingslashit( $path ) {
		return rtrim( $path, '/\\' ) . DIRECTORY_SEPARATOR;
	}

	/**
	 * Get the logging level for the plugin.
	 *
	 * Default is ERROR.
	 *
	 * Assumes that cycling-club-manager plugin is active.
	 * Otherwise, will default to ERROR
	 *
	 * Hierarchy of logging levels:
	 *   DEBUG: Detailed debug information.
	 *   INFO: Interesting events. Examples: User logs in, SQL logs.
	 *   NOTICE: Normal but significant events.
	 *   WARNING: Exceptional occurrences that are not errors. Examples: Use of deprecated APIs, poor use of an API, undesirable things that are not necessarily wrong.
	 *   ERROR: Runtime errors that do not require immediate action but should typically be logged and monitored.
	 *   CRITICAL: Critical conditions. Example: Application component unavailable, unexpected exception.
	 *   ALERT: Action must be taken immediately. Example: Entire website down, database unavailable, etc. This should trigger the SMS alerts and wake you up.
	 *   EMERGENCY: Emergency: system is unusable.
	 */
	private function get_logging_level() {

		// if ( is_multisite() ) {
		//  $main_site_id = get_main_site_id(); // Get the main site ID.
		//  switch_to_blog( $main_site_id );
		//  $site_settings = get_option( 'phpffa_settings' );
		//  restore_current_blog();
		// } else {
		//  $site_settings = get_option( 'phpffa_settings' );
		// }

		// $logging_level = isset( $site_settings['logging_level'] ) ? $site_settings['logging_level'] : Logger::ERROR;

		return Level::Debug;
	}
}

// phpcs:enable WordPress
// phpcs:enable Squiz.Commenting
// phpcs:enable Generic.Commenting.DocComment.ShortNotCapital
