/**
 * Airtable Daily Stats Job Admin Settings
 *
 * Registers the settings UI for the Airtable job.
 *
 * @package
 */

import { addFilter } from '@wordpress/hooks';
import { TextControl, ToggleControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

/**
 * Render Airtable job settings.
 * @param root0
 * @param root0.settings
 * @param root0.onUpdate
 */
function AirtableJobSettings( { settings, onUpdate } ) {
	return (
		<div className="job-settings">
			<ToggleControl
				__nextHasNoMarginBottom
				label={ __( 'Enable Sync', 'workflow-job-airtable' ) }
				checked={ settings.enabled || false }
				onChange={ ( val ) => onUpdate( 'enabled', val ) }
			/>
			<TextControl
				__next40pxDefaultSize
				__nextHasNoMarginBottom
				type="password"
				label={ __( 'API Key', 'workflow-job-airtable' ) }
				value={ settings.api_key || '' }
				onChange={ ( val ) => onUpdate( 'api_key', val ) }
				help={ __(
					'Get from airtable.com/create/tokens',
					'workflow-job-airtable'
				) }
			/>
			<TextControl
				__next40pxDefaultSize
				__nextHasNoMarginBottom
				label={ __( 'Base ID', 'workflow-job-airtable' ) }
				value={ settings.base_id || '' }
				onChange={ ( val ) => onUpdate( 'base_id', val ) }
				placeholder="appXXXXXXXXXXXXXX"
			/>
			<TextControl
				__next40pxDefaultSize
				__nextHasNoMarginBottom
				label={ __( 'Table ID', 'workflow-job-airtable' ) }
				value={ settings.table_id || '' }
				onChange={ ( val ) => onUpdate( 'table_id', val ) }
				placeholder="tblXXXXXXXXXXXXXX"
			/>
		</div>
	);
}

// Register the settings component for the Airtable job.
addFilter(
	'vipWorkflow.jobSettingsComponent',
	'workflow-job-airtable',
	( component, jobId, props ) => {
		if ( jobId === 'airtable_daily_stats' ) {
			return <AirtableJobSettings { ...props } />;
		}
		return component;
	}
);
