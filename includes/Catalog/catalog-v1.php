<?php
/**
 * Question catalog, version 1.
 *
 * Transcribed from Residential_Electrical_Service_Inspection_Punch_List_Fillable.pdf.
 *
 * Item keys are permanent. Surveys store a snapshot of this file at creation, so labels and
 * severities may be edited for future surveys without rewriting historical ones — but a key must
 * never be reused for a different question.
 *
 * severity  Default action-plan bucket when an item fails. Reviewers can override per survey.
 * proposal  Customer-facing rewording of the finding. Written panel-neutral; the proposal builder
 *           prefixes the panel label for panel-scoped items.
 * input     'status' = Pass / Fail / N/A. 'task' = a procedural step, Done / Not Done / N/A.
 *
 * Sections carry:
 * scope     'survey' (asked once) or 'panel' (repeated for each panel on the property).
 * polarity  'normal' = a failure is a problem. 'hazard' = the question asks whether something is
 *           present, so the UI reads Not Present / Present / N/A while storing pass / fail.
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return [
	'version'  => 1,

	'sections' => [

		[
			'key'   => 'service_equipment',
			'label' => __( 'Service Equipment', 'paumalu-site-survey' ),
			'scope' => 'survey',
			'items' => [
				[
					'key'      => 'svc_meter_enclosure',
					'label'    => __( 'Inspect meter enclosure for corrosion, damage, water intrusion, and secure mounting.', 'paumalu-site-survey' ),
					'severity' => 'immediate',
					'proposal' => __( 'The meter enclosure shows corrosion, damage, or water intrusion. Repair or replacement is needed to keep moisture out of the service equipment.', 'paumalu-site-survey' ),
				],
				[
					'key'      => 'svc_mast_riser',
					'label'    => __( 'Inspect service mast/riser, weatherhead, and service entrance conductors.', 'paumalu-site-survey' ),
					'severity' => 'immediate',
					'proposal' => __( 'The service mast, weatherhead, or service entrance conductors are damaged or deteriorated. This is the unprotected point where utility power enters the home and should be corrected promptly.', 'paumalu-site-survey' ),
				],
				[
					'key'      => 'svc_attachment_clearance',
					'label'    => __( 'Check service attachment point and clearances (visual).', 'paumalu-site-survey' ),
					'severity' => 'recommended',
					'proposal' => __( 'The service attachment point or overhead clearances do not meet current requirements. Correction is recommended.', 'paumalu-site-survey' ),
				],
			],
		],

		[
			'key'      => 'load_center_exterior',
			'label'    => __( 'Load Center — Enclosure', 'paumalu-site-survey' ),
			'scope'    => 'panel',
			'items'    => [
				[
					'key'      => 'lc_enclosure_condition',
					'label'    => __( 'Inspect main load center enclosure condition.', 'paumalu-site-survey' ),
					'severity' => 'recommended',
					'proposal' => __( 'The panel enclosure is damaged or deteriorated and should be repaired or replaced.', 'paumalu-site-survey' ),
				],
				[
					'key'      => 'lc_dead_front',
					'label'    => __( 'Inspect dead front and panel cover.', 'paumalu-site-survey' ),
					'severity' => 'immediate',
					'proposal' => __( 'The dead front or panel cover is damaged, missing, or does not fit correctly, leaving energized parts accessible. This is a shock hazard and should be corrected right away.', 'paumalu-site-survey' ),
				],
				[
					'key'      => 'lc_rust_moisture',
					'label'    => __( 'Check for rust, corrosion, insect intrusion, or moisture.', 'paumalu-site-survey' ),
					'severity' => 'recommended',
					'proposal' => __( 'Rust, corrosion, moisture, or insect intrusion was found inside the panel. Left untreated this degrades connections and leads to overheating.', 'paumalu-site-survey' ),
				],
				[
					'key'      => 'lc_labeling',
					'label'    => __( 'Verify panel labeling is present and reasonably accurate.', 'paumalu-site-survey' ),
					'severity' => 'optional',
					'proposal' => __( 'The circuit directory is missing, incomplete, or inaccurate. Accurate labeling matters in an emergency and when isolating a circuit for service.', 'paumalu-site-survey' ),
				],
				[
					'key'      => 'lc_openings_closed',
					'label'    => __( 'Verify all panel openings are properly closed.', 'paumalu-site-survey' ),
					'severity' => 'immediate',
					'proposal' => __( 'Open knockouts or unfilled breaker spaces leave energized parts reachable. Filler plates or connectors should be installed.', 'paumalu-site-survey' ),
				],
			],
		],

		[
			'key'      => 'load_center_interior',
			'label'    => __( 'Interior of Load Center', 'paumalu-site-survey' ),
			'scope'    => 'panel',
			'items'    => [
				[
					'key'      => 'lci_remove_dead_front',
					'label'    => __( 'Remove dead front.', 'paumalu-site-survey' ),
					'input'    => 'task',
					'severity' => 'recommended',
					'proposal' => __( 'The panel interior could not be opened and inspected during this visit. A follow-up visit is recommended so the interior can be evaluated.', 'paumalu-site-survey' ),
				],
				[
					'key'      => 'lci_overheating',
					'label'    => __( 'Check for overheating, discoloration, or arcing.', 'paumalu-site-survey' ),
					'severity' => 'immediate',
					'proposal' => __( 'Signs of overheating, discoloration, or arcing were found inside the panel. This indicates an active fault condition and should be repaired immediately.', 'paumalu-site-survey' ),
				],
				[
					'key'      => 'lci_bus_bars',
					'label'    => __( 'Inspect bus bars.', 'paumalu-site-survey' ),
					'severity' => 'immediate',
					'proposal' => __( 'The bus bars show pitting, burning, or damage. Damaged bus bars cannot be repaired in place and generally require panel replacement.', 'paumalu-site-survey' ),
				],
				[
					'key'      => 'lci_main_breaker',
					'label'    => __( 'Inspect main breaker condition.', 'paumalu-site-survey' ),
					'severity' => 'immediate',
					'proposal' => __( 'The main breaker is damaged or not operating correctly. This is the primary disconnect for the home and should be replaced promptly.', 'paumalu-site-survey' ),
				],
				[
					'key'      => 'lci_branch_breakers',
					'label'    => __( 'Inspect branch breaker condition.', 'paumalu-site-survey' ),
					'severity' => 'recommended',
					'proposal' => __( 'One or more branch breakers are damaged, corroded, or not seated correctly and should be replaced.', 'paumalu-site-survey' ),
				],
				[
					'key'      => 'lci_incompatible_breakers',
					'label'    => __( 'Check for incompatible breaker types.', 'paumalu-site-survey' ),
					'severity' => 'immediate',
					'proposal' => __( 'Breakers not listed for this panel are installed. Mismatched breakers may not seat properly on the bus and can overheat or fail to trip. Replacement with listed breakers is needed.', 'paumalu-site-survey' ),
				],
				[
					'key'      => 'lci_double_lugged',
					'label'    => __( 'Check for double-lugged breakers where not permitted.', 'paumalu-site-survey' ),
					'severity' => 'immediate',
					'proposal' => __( 'Two conductors are landed under a breaker terminal rated for one. This is a loose-connection and overheating risk and should be corrected.', 'paumalu-site-survey' ),
				],
				[
					'key'      => 'lci_neutral_bar',
					'label'    => __( 'Check neutral bar for multiple conductors where prohibited.', 'paumalu-site-survey' ),
					'severity' => 'recommended',
					'proposal' => __( 'Multiple conductors are landed under single neutral bar terminals where only one is permitted. Separating these terminations is recommended.', 'paumalu-site-survey' ),
				],
				[
					'key'      => 'lci_grounding_terminations',
					'label'    => __( 'Verify grounding conductor terminations.', 'paumalu-site-survey' ),
					'severity' => 'immediate',
					'proposal' => __( 'Grounding conductor terminations are loose, missing, or incorrect. The grounding system is what clears a fault safely, so this should be corrected right away.', 'paumalu-site-survey' ),
				],
				[
					'key'      => 'lci_bonding_screw',
					'label'    => __( 'Verify bonding screw/strap where applicable.', 'paumalu-site-survey' ),
					'severity' => 'immediate',
					'proposal' => __( 'The main bonding screw or strap is missing or incorrectly installed. Correct bonding is required for overcurrent devices to trip on a ground fault.', 'paumalu-site-survey' ),
				],
				[
					'key'      => 'lci_insulation',
					'label'    => __( 'Inspect conductor insulation condition.', 'paumalu-site-survey' ),
					'severity' => 'immediate',
					'proposal' => __( 'Conductor insulation is cracked, brittle, heat-damaged, or missing, exposing bare conductor inside the panel. Affected conductors should be repaired or replaced.', 'paumalu-site-survey' ),
				],
				[
					'key'      => 'lci_routing_workmanship',
					'label'    => __( 'Check conductor routing and workmanship.', 'paumalu-site-survey' ),
					'severity' => 'recommended',
					'proposal' => __( 'Conductor routing and workmanship inside the panel are substandard, which makes future service harder and can stress terminations. Re-dressing the conductors is recommended.', 'paumalu-site-survey' ),
				],
				[
					'key'      => 'lci_connectors_secure',
					'label'    => __( 'Verify cable connectors are secure.', 'paumalu-site-survey' ),
					'severity' => 'recommended',
					'proposal' => __( 'Cable connectors entering the panel are loose or missing, leaving cables unsupported against the enclosure edge. Proper connectors should be installed.', 'paumalu-site-survey' ),
				],
				[
					'key'      => 'lci_torque_lugs',
					'label'    => __( 'Tighten accessible lugs and terminals to manufacturer specifications where applicable.', 'paumalu-site-survey' ),
					'input'    => 'task',
					'severity' => 'recommended',
					'proposal' => __( 'Accessible lugs and terminals were not able to be torqued to specification during this visit. Loose terminations are a leading cause of panel overheating, so this should be scheduled.', 'paumalu-site-survey' ),
				],
			],
			'readings' => [
				[
					'key'   => 'l1_n',
					'label' => __( 'L1 to Neutral', 'paumalu-site-survey' ),
					'unit'  => 'V',
					'min'   => 0,
					'max'   => 300,
				],
				[
					'key'   => 'l2_n',
					'label' => __( 'L2 to Neutral', 'paumalu-site-survey' ),
					'unit'  => 'V',
					'min'   => 0,
					'max'   => 300,
				],
				[
					'key'   => 'l1_l2',
					'label' => __( 'L1 to L2', 'paumalu-site-survey' ),
					'unit'  => 'V',
					'min'   => 0,
					'max'   => 600,
				],
				[
					'key'   => 'load_l1',
					'label' => __( 'Measured load, L1', 'paumalu-site-survey' ),
					'unit'  => 'A',
					'min'   => 0,
					'max'   => 600,
				],
				[
					'key'   => 'load_l2',
					'label' => __( 'Measured load, L2', 'paumalu-site-survey' ),
					'unit'  => 'A',
					'min'   => 0,
					'max'   => 600,
				],
			],
		],

		[
			'key'   => 'electrical_testing',
			'label' => __( 'Electrical Testing', 'paumalu-site-survey' ),
			'scope' => 'panel',
			'items' => [
				[
					'key'      => 'et_service_voltage',
					'label'    => __( 'Verify service voltage.', 'paumalu-site-survey' ),
					'severity' => 'immediate',
					'proposal' => __( 'Measured service voltage is outside the expected range. Voltage this far off can damage appliances and electronics and should be investigated with the utility.', 'paumalu-site-survey' ),
				],
				[
					'key'      => 'et_voltage_balance',
					'label'    => __( 'Verify voltage balance between legs.', 'paumalu-site-survey' ),
					'severity' => 'recommended',
					'proposal' => __( 'The two service legs are measurably unbalanced. Rebalancing circuits across the legs is recommended to reduce heat and improve efficiency.', 'paumalu-site-survey' ),
				],
				[
					'key'      => 'et_main_load',
					'label'    => __( 'Measure main load under normal operating conditions.', 'paumalu-site-survey' ),
					'severity' => 'recommended',
					'proposal' => __( 'Measured load is approaching the rated capacity of the service. A service capacity review is recommended before adding new loads.', 'paumalu-site-survey' ),
				],
				[
					'key'      => 'et_infrared_scan',
					'label'    => __( 'Scan panel for abnormal heating (infrared if available).', 'paumalu-site-survey' ),
					'severity' => 'immediate',
					'proposal' => __( 'Abnormal heating was detected in the panel. Hot spots indicate a loose or failing connection and should be corrected before they fail.', 'paumalu-site-survey' ),
				],
				[
					'key'      => 'et_branch_spot_check',
					'label'    => __( 'Spot-check several branch circuits for proper voltage.', 'paumalu-site-survey' ),
					'severity' => 'recommended',
					'proposal' => __( 'One or more branch circuits did not read correct voltage. Further diagnosis of the affected circuits is recommended.', 'paumalu-site-survey' ),
				],
			],
		],

		[
			'key'   => 'grounding_bonding',
			'label' => __( 'Grounding & Bonding', 'paumalu-site-survey' ),
			'scope' => 'survey',
			'items' => [
				[
					'key'      => 'gb_electrode_conductor',
					'label'    => __( 'Inspect grounding electrode conductor.', 'paumalu-site-survey' ),
					'severity' => 'immediate',
					'proposal' => __( 'The grounding electrode conductor is damaged, undersized, or improperly connected. This conductor is what gives fault current a path to earth and should be corrected right away.', 'paumalu-site-survey' ),
				],
				[
					'key'      => 'gb_ground_rod',
					'label'    => __( 'Inspect ground rod connection (if visible).', 'paumalu-site-survey' ),
					'severity' => 'recommended',
					'proposal' => __( 'The ground rod connection is corroded, loose, or incomplete. Restoring a solid connection is recommended.', 'paumalu-site-survey' ),
				],
				[
					'key'      => 'gb_water_pipe',
					'label'    => __( 'Inspect water pipe grounding connection (if applicable).', 'paumalu-site-survey' ),
					'severity' => 'recommended',
					'proposal' => __( 'The water pipe grounding connection is missing, corroded, or has been interrupted by non-metallic piping. Correction is recommended.', 'paumalu-site-survey' ),
				],
				[
					'key'      => 'gb_bonding_jumpers',
					'label'    => __( 'Inspect bonding jumpers.', 'paumalu-site-survey' ),
					'severity' => 'immediate',
					'proposal' => __( 'Required bonding jumpers are missing or damaged, leaving metallic systems unbonded. This should be corrected right away.', 'paumalu-site-survey' ),
				],
			],
		],

		[
			'key'   => 'afci_gfci',
			'label' => __( 'AFCI / GFCI Protection', 'paumalu-site-survey' ),
			'scope' => 'survey',
			'items' => [
				[
					'key'      => 'gf_test_devices',
					'label'    => __( 'Test accessible GFCI devices.', 'paumalu-site-survey' ),
					'severity' => 'immediate',
					'proposal' => __( 'One or more GFCI devices failed to trip when tested. A GFCI that will not trip offers no shock protection and should be replaced.', 'paumalu-site-survey' ),
				],
				[
					'key'      => 'gf_kitchen',
					'label'    => __( 'Kitchen GFCI protection', 'paumalu-site-survey' ),
					'severity' => 'recommended',
					'proposal' => __( 'Kitchen countertop receptacles lack GFCI protection. Adding it is recommended wherever receptacles are near a sink.', 'paumalu-site-survey' ),
				],
				[
					'key'      => 'gf_bathrooms',
					'label'    => __( 'Bathrooms GFCI protection', 'paumalu-site-survey' ),
					'severity' => 'recommended',
					'proposal' => __( 'Bathroom receptacles lack GFCI protection. Adding it is recommended.', 'paumalu-site-survey' ),
				],
				[
					'key'      => 'gf_garage',
					'label'    => __( 'Garage GFCI protection', 'paumalu-site-survey' ),
					'severity' => 'recommended',
					'proposal' => __( 'Garage receptacles lack GFCI protection. Adding it is recommended.', 'paumalu-site-survey' ),
				],
				[
					'key'      => 'gf_exterior',
					'label'    => __( 'Exterior GFCI protection', 'paumalu-site-survey' ),
					'severity' => 'recommended',
					'proposal' => __( 'Exterior receptacles lack GFCI protection. Given constant exposure to weather and salt air, adding it is recommended.', 'paumalu-site-survey' ),
				],
				[
					'key'      => 'gf_laundry',
					'label'    => __( 'Laundry GFCI protection', 'paumalu-site-survey' ),
					'severity' => 'recommended',
					'proposal' => __( 'Laundry area receptacles lack GFCI protection. Adding it is recommended.', 'paumalu-site-survey' ),
				],
				[
					'key'      => 'gf_crawl_basement',
					'label'    => __( 'Crawl space/basement GFCI protection', 'paumalu-site-survey' ),
					'severity' => 'recommended',
					'proposal' => __( 'Crawl space or basement receptacles lack GFCI protection. Adding it is recommended in these damp locations.', 'paumalu-site-survey' ),
				],
				[
					'key'      => 'gf_missing_overall',
					'label'    => __( 'Identify missing GFCI protection.', 'paumalu-site-survey' ),
					'severity' => 'recommended',
					'proposal' => __( 'Additional locations were identified that require GFCI protection but do not have it.', 'paumalu-site-survey' ),
				],
				[
					'key'      => 'af_missing',
					'label'    => __( 'Identify missing AFCI protection where applicable.', 'paumalu-site-survey' ),
					'severity' => 'optional',
					'proposal' => __( 'Circuits that would require arc-fault protection under current standards are not protected. AFCI breakers detect arcing faults behind walls and are a worthwhile retrofit.', 'paumalu-site-survey' ),
				],
			],
		],

		[
			'key'   => 'smoke_safety',
			'label' => __( 'Smoke & Safety Devices', 'paumalu-site-survey' ),
			'scope' => 'survey',
			'items' => [
				[
					'key'      => 'sd_smoke_alarms',
					'label'    => __( 'Test smoke alarms.', 'paumalu-site-survey' ),
					'severity' => 'immediate',
					'proposal' => __( 'One or more smoke alarms failed to sound when tested, or coverage is missing. Working smoke alarms are the single most effective life-safety device in a home.', 'paumalu-site-survey' ),
				],
				[
					'key'      => 'sd_combo_co',
					'label'    => __( 'Test combination smoke/CO alarms.', 'paumalu-site-survey' ),
					'severity' => 'immediate',
					'proposal' => __( 'One or more combination smoke and carbon monoxide alarms failed to sound when tested, or are missing where needed.', 'paumalu-site-survey' ),
				],
				[
					'key'      => 'sd_over_ten_years',
					'label'    => __( 'Recommend replacement if over 10 years old.', 'paumalu-site-survey' ),
					'severity' => 'recommended',
					'proposal' => __( 'Alarms in this home are at or past their ten-year service life. Sensors lose sensitivity with age and manufacturers specify replacement at ten years regardless of whether the unit still chirps.', 'paumalu-site-survey' ),
				],
			],
		],

		[
			'key'   => 'interior_electrical',
			'label' => __( 'Interior Electrical', 'paumalu-site-survey' ),
			'scope' => 'survey',
			'items' => [
				[
					'key'      => 'int_test_receptacles',
					'label'    => __( 'Test representative receptacles.', 'paumalu-site-survey' ),
					'severity' => 'recommended',
					'proposal' => __( 'One or more tested receptacles did not perform correctly and should be repaired or replaced.', 'paumalu-site-survey' ),
				],
				[
					'key'      => 'int_polarity_grounding',
					'label'    => __( 'Check polarity and grounding.', 'paumalu-site-survey' ),
					'severity' => 'immediate',
					'proposal' => __( 'Reversed polarity or an open ground was found at one or more receptacles. Both conditions defeat the safety design of connected equipment and should be corrected.', 'paumalu-site-survey' ),
				],
				[
					'key'      => 'int_loose_devices',
					'label'    => __( 'Check for loose devices.', 'paumalu-site-survey' ),
					'severity' => 'recommended',
					'proposal' => __( 'Loose receptacles or switches were found. Movement works terminations loose over time and leads to arcing.', 'paumalu-site-survey' ),
				],
				[
					'key'      => 'int_switches',
					'label'    => __( 'Inspect switches.', 'paumalu-site-survey' ),
					'severity' => 'recommended',
					'proposal' => __( 'One or more switches are worn or not operating correctly and should be replaced.', 'paumalu-site-survey' ),
				],
				[
					'key'      => 'int_lighting',
					'label'    => __( 'Inspect lighting fixtures.', 'paumalu-site-survey' ),
					'severity' => 'recommended',
					'proposal' => __( 'One or more lighting fixtures are damaged, improperly supported, or improperly wired and should be serviced.', 'paumalu-site-survey' ),
				],
				[
					'key'      => 'int_damaged_receptacles',
					'label'    => __( 'Check for damaged receptacles.', 'paumalu-site-survey' ),
					'severity' => 'recommended',
					'proposal' => __( 'Damaged or worn receptacles were found. Worn contacts no longer grip plugs firmly, which causes heat at the connection.', 'paumalu-site-survey' ),
				],
				[
					'key'      => 'int_damaged_plates',
					'label'    => __( 'Check for damaged switch plates.', 'paumalu-site-survey' ),
					'severity' => 'optional',
					'proposal' => __( 'Cracked or missing cover plates were found and should be replaced.', 'paumalu-site-survey' ),
				],
			],
		],

		[
			'key'   => 'exterior_electrical',
			'label' => __( 'Exterior Electrical', 'paumalu-site-survey' ),
			'scope' => 'survey',
			'items' => [
				[
					'key'      => 'ext_receptacles',
					'label'    => __( 'Inspect exterior receptacles.', 'paumalu-site-survey' ),
					'severity' => 'recommended',
					'proposal' => __( 'Exterior receptacles are damaged or deteriorated and should be replaced.', 'paumalu-site-survey' ),
				],
				[
					'key'      => 'ext_weather_resistant',
					'label'    => __( 'Verify weather-resistant devices.', 'paumalu-site-survey' ),
					'severity' => 'recommended',
					'proposal' => __( 'Exterior receptacles are not weather-resistant rated. In this coastal environment, standard devices corrode quickly and should be upgraded.', 'paumalu-site-survey' ),
				],
				[
					'key'      => 'ext_in_use_covers',
					'label'    => __( 'Verify in-use covers.', 'paumalu-site-survey' ),
					'severity' => 'recommended',
					'proposal' => __( 'Exterior receptacles lack in-use ("bubble") covers, so they are only weatherproof when nothing is plugged in. Proper covers should be installed.', 'paumalu-site-survey' ),
				],
				[
					'key'      => 'ext_lighting',
					'label'    => __( 'Inspect exterior lighting.', 'paumalu-site-survey' ),
					'severity' => 'optional',
					'proposal' => __( 'Exterior lighting is damaged or not functioning and could be repaired or upgraded.', 'paumalu-site-survey' ),
				],
				[
					'key'      => 'ext_conduit_fittings',
					'label'    => __( 'Inspect exposed conduit and fittings.', 'paumalu-site-survey' ),
					'severity' => 'recommended',
					'proposal' => __( 'Exposed conduit or fittings are damaged, corroded, or unsupported, allowing water into the raceway. Repair is recommended.', 'paumalu-site-survey' ),
				],
			],
		],

		[
			'key'   => 'attic_crawlspace',
			'label' => __( 'Attic / Crawlspace', 'paumalu-site-survey' ),
			'scope' => 'survey',
			'items' => [
				[
					'key'      => 'ac_exposed_wiring',
					'label'    => __( 'Inspect exposed wiring.', 'paumalu-site-survey' ),
					'severity' => 'immediate',
					'proposal' => __( 'Exposed or unprotected wiring was found in the attic or crawlspace and should be properly enclosed and protected.', 'paumalu-site-survey' ),
				],
				[
					'key'      => 'ac_damaged_nm',
					'label'    => __( 'Look for damaged NM cable.', 'paumalu-site-survey' ),
					'severity' => 'immediate',
					'proposal' => __( 'Damaged non-metallic cable was found. Compromised cable jacket or insulation is a fire and shock risk and should be repaired or replaced.', 'paumalu-site-survey' ),
				],
				[
					'key'      => 'ac_exposed_splices',
					'label'    => __( 'Check for exposed splices.', 'paumalu-site-survey' ),
					'severity' => 'immediate',
					'proposal' => __( 'Splices were found outside of an approved junction box. Unenclosed splices are one of the most common causes of concealed electrical fires and should be corrected right away.', 'paumalu-site-survey' ),
				],
				[
					'key'      => 'ac_cable_support',
					'label'    => __( 'Check support of cables.', 'paumalu-site-survey' ),
					'severity' => 'recommended',
					'proposal' => __( 'Cables are unsupported or improperly secured. Adding proper supports is recommended to prevent strain on terminations.', 'paumalu-site-survey' ),
				],
				[
					'key'      => 'ac_rodent_damage',
					'label'    => __( 'Look for rodent damage.', 'paumalu-site-survey' ),
					'severity' => 'immediate',
					'proposal' => __( 'Rodent damage to wiring was found. Chewed insulation exposes live conductors in concealed spaces and should be repaired right away.', 'paumalu-site-survey' ),
				],
			],
		],

		[
			'key'      => 'safety_hazards',
			'label'    => __( 'Safety Hazards', 'paumalu-site-survey' ),
			'scope'    => 'survey',
			'polarity' => 'hazard',
			'items'    => [
				[
					'key'      => 'hz_overheating',
					'label'    => __( 'Evidence of overheating.', 'paumalu-site-survey' ),
					'severity' => 'immediate',
					'proposal' => __( 'Evidence of overheating was found. This indicates a connection that is already failing and should be addressed immediately.', 'paumalu-site-survey' ),
				],
				[
					'key'      => 'hz_aluminum_branch',
					'label'    => __( 'Aluminum branch wiring.', 'paumalu-site-survey' ),
					'severity' => 'immediate',
					'proposal' => __( 'The home has aluminum branch circuit wiring. Aluminum expands and contracts differently than copper, loosening connections over time; homes with it have a markedly higher fire risk. Remediation with approved connectors at every device is strongly recommended.', 'paumalu-site-survey' ),
				],
				[
					'key'      => 'hz_federal_pacific',
					'label'    => __( 'Federal Pacific equipment.', 'paumalu-site-survey' ),
					'severity' => 'immediate',
					'proposal' => __( 'The home has a Federal Pacific Stab-Lok panel. Independent testing has shown these breakers frequently fail to trip on an overload, meaning the panel may not protect the home\'s wiring at all. Replacement is strongly recommended.', 'paumalu-site-survey' ),
				],
				[
					'key'      => 'hz_zinsco',
					'label'    => __( 'Zinsco equipment.', 'paumalu-site-survey' ),
					'severity' => 'immediate',
					'proposal' => __( 'The home has Zinsco equipment. These panels are known for breakers that fuse to the bus and fail to trip, and replacement parts are no longer manufactured. Replacement is strongly recommended.', 'paumalu-site-survey' ),
				],
				[
					'key'      => 'hz_challenger',
					'label'    => __( 'Challenger equipment.', 'paumalu-site-survey' ),
					'severity' => 'immediate',
					'proposal' => __( 'The home has Challenger equipment, which has a documented history of overheating breakers and failed trips. Replacement is strongly recommended.', 'paumalu-site-survey' ),
				],
				[
					'key'      => 'hz_open_splices',
					'label'    => __( 'Open splices.', 'paumalu-site-survey' ),
					'severity' => 'immediate',
					'proposal' => __( 'Open splices were found outside an approved enclosure. These should be placed in proper junction boxes right away.', 'paumalu-site-survey' ),
				],
				[
					'key'      => 'hz_extension_cords',
					'label'    => __( 'Extension cords used as permanent wiring.', 'paumalu-site-survey' ),
					'severity' => 'immediate',
					'proposal' => __( 'Extension cords are being used as permanent wiring. Cords are not rated for continuous concealed use and indicate the home is short of the circuits it needs. Adding permanent circuits is recommended.', 'paumalu-site-survey' ),
				],
				[
					'key'      => 'hz_missing_directory',
					'label'    => __( 'Missing panel directory.', 'paumalu-site-survey' ),
					'severity' => 'optional',
					'proposal' => __( 'The panel has no circuit directory. Mapping and labeling the circuits is a small job that pays off in every future service call and in an emergency.', 'paumalu-site-survey' ),
				],
				[
					'key'      => 'hz_missing_bonding',
					'label'    => __( 'Missing bonding.', 'paumalu-site-survey' ),
					'severity' => 'immediate',
					'proposal' => __( 'Required bonding is missing. Without it, overcurrent devices may not trip during a ground fault and metallic systems can become energized.', 'paumalu-site-survey' ),
				],
				[
					'key'      => 'hz_water_intrusion',
					'label'    => __( 'Water intrusion.', 'paumalu-site-survey' ),
					'severity' => 'immediate',
					'proposal' => __( 'Water intrusion was found in electrical equipment. Water and energized equipment together are an immediate shock and fire hazard, and the source should be corrected along with the damaged equipment.', 'paumalu-site-survey' ),
				],
				[
					'key'      => 'hz_corrosion',
					'label'    => __( 'Corrosion.', 'paumalu-site-survey' ),
					'severity' => 'recommended',
					'proposal' => __( 'Corrosion was found on electrical equipment. On the North Shore, salt air accelerates this considerably; corroded terminations lose conductivity and generate heat.', 'paumalu-site-survey' ),
				],
			],
		],
	],

	/**
	 * Proactive upgrades, not on the original punch list. These are opportunities rather than
	 * failures, so they cannot be derived from the checklist above — without them the Optional
	 * Upgrades bucket of the action plan stays empty.
	 */
	'upgrades' => [
		[
			'key'      => 'up_surge_protection',
			'label'    => __( 'Whole-home surge protection', 'paumalu-site-survey' ),
			'proposal' => __( 'A whole-home surge protective device installed at the panel protects every circuit from utility switching surges and nearby lightning, which no plug-in strip can do.', 'paumalu-site-survey' ),
		],
		[
			'key'      => 'up_ev_charger',
			'label'    => __( 'EV charger circuit', 'paumalu-site-survey' ),
			'proposal' => __( 'A dedicated 240V circuit for an electric vehicle charger. Installing the circuit now, while the panel is open, costs considerably less than returning for it later.', 'paumalu-site-survey' ),
		],
		[
			'key'      => 'up_panel_upgrade',
			'label'    => __( 'Panel or service upgrade', 'paumalu-site-survey' ),
			'proposal' => __( 'Upgrading the service and load center increases available capacity, adds breaker spaces for future circuits, and brings the home\'s central equipment up to current standards.', 'paumalu-site-survey' ),
		],
		[
			'key'      => 'up_generator_interlock',
			'label'    => __( 'Generator interlock or transfer switch', 'paumalu-site-survey' ),
			'proposal' => __( 'A code-compliant interlock or transfer switch lets a portable generator safely power selected circuits during an outage, without the backfeed risk of a suicide cord.', 'paumalu-site-survey' ),
		],
		[
			'key'      => 'up_alarm_modernization',
			'label'    => __( 'Smoke & CO alarm modernization', 'paumalu-site-survey' ),
			'proposal' => __( 'Replacing aging alarms with interconnected ten-year sealed units means every alarm sounds when any one detects smoke, and no battery changes for a decade.', 'paumalu-site-survey' ),
		],
		[
			'key'      => 'up_exterior_lighting',
			'label'    => __( 'Exterior & landscape lighting', 'paumalu-site-survey' ),
			'proposal' => __( 'Added exterior lighting improves safety on steps and walkways and deters intruders.', 'paumalu-site-survey' ),
		],
		[
			'key'      => 'up_pv_readiness',
			'label'    => __( 'Photovoltaic readiness', 'paumalu-site-survey' ),
			'proposal' => __( 'Preparing the service for a future photovoltaic system — adequate busbar rating, breaker space, and conduit paths — avoids a second panel upgrade when the system is installed.', 'paumalu-site-survey' ),
		],
		[
			'key'      => 'up_afci_retrofit',
			'label'    => __( 'AFCI breaker retrofit', 'paumalu-site-survey' ),
			'proposal' => __( 'Arc-fault breakers detect the kind of low-level arcing that standard breakers ignore, which is the failure mode behind many concealed wiring fires.', 'paumalu-site-survey' ),
		],
		[
			'key'      => 'up_dedicated_circuits',
			'label'    => __( 'Additional dedicated circuits', 'paumalu-site-survey' ),
			'proposal' => __( 'Adding dedicated circuits for high-draw appliances relieves overloaded shared circuits and stops nuisance tripping.', 'paumalu-site-survey' ),
		],
	],

	'summary'  => [
		'conditions' => [
			'excellent' => __( 'Excellent', 'paumalu-site-survey' ),
			'good'      => __( 'Good', 'paumalu-site-survey' ),
			'fair'      => __( 'Fair', 'paumalu-site-survey' ),
			'poor'      => __( 'Poor', 'paumalu-site-survey' ),
		],
		'timeframes' => [
			'immediate'      => __( 'Immediate', 'paumalu-site-survey' ),
			'within_6months' => __( 'Within 6 months', 'paumalu-site-survey' ),
			'within_1year'   => __( 'Within 1 year', 'paumalu-site-survey' ),
			'monitor'        => __( 'Monitor', 'paumalu-site-survey' ),
		],
	],
];
