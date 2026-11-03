import apiFetch from '@wordpress/api-fetch';
import { Button, CheckboxControl, Notice } from '@wordpress/components';
import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { Stack, Text } from '@wordpress/ui';
import { Section } from './Section';
import { unwrapAbilityResponse } from './ability';
import type { ReindexResponse } from './types';

interface Props {
	isSupported: boolean;
	installed: boolean;
	dimChanged: boolean;
	curDims: number | null;
	tableDims: number | null;
	onReindexed: ( rebuilt: boolean ) => void;
}

export function ReindexSection( {
	isSupported,
	installed,
	dimChanged,
	curDims,
	tableDims,
	onReindexed,
}: Props ) {
	const [ force, setForce ] = useState( false );
	const [ confirmRebuild, setConfirmRebuild ] = useState( false );
	const [ isSubmitting, setIsSubmitting ] = useState( false );
	const [ error, setError ] = useState< string | null >( null );

	if ( ! isSupported ) {
		return null;
	}

	async function handleSubmit() {
		setIsSubmitting( true );
		setError( null );

		try {
			const result = await apiFetch( {
				path: '/wp-abilities/v1/abilities/squirrel-search/reindex/run',
				method: 'POST',
				data: {
					input: {
						force,
						confirm_rebuild: confirmRebuild,
					},
				},
			} );
			const rebuilt =
				unwrapAbilityResponse< ReindexResponse >( result )?.rebuilt ??
				false;
			onReindexed( rebuilt );
		} catch ( err ) {
			const message =
				err instanceof Error
					? err.message
					: __( 'Unknown error.', 'squirrel-search' );
			setError( message );
		} finally {
			setIsSubmitting( false );
		}
	}

	return (
		<Section title={ __( 'Reindex', 'squirrel-search' ) }>
			{ error && (
				<Notice status="error" onRemove={ () => setError( null ) }>
					{ error }
				</Notice>
			) }
			{ dimChanged && (
				<Stack direction="column" gap="md">
					<Notice status="warning" isDismissible={ false }>
						{ __( 'Selected model is', 'squirrel-search' ) }{ ' ' }
						{ curDims }
						{ '-dim but the table is ' }
						{ tableDims }
						{ __(
							'-dim. Reindexing will recreate the table and delete all existing vectors.',
							'squirrel-search'
						) }
					</Notice>
					<CheckboxControl
						label={ __(
							'I understand that all existing vectors will be deleted.',
							'squirrel-search'
						) }
						checked={ confirmRebuild }
						onChange={ setConfirmRebuild }
					/>
				</Stack>
			) }
			{ ! dimChanged && ! installed && (
				<Text render={ <p /> }>
					{ __(
						'The embeddings table will be created and a full reindex will be scheduled.',
						'squirrel-search'
					) }
				</Text>
			) }
			{ ! dimChanged && installed && (
				<CheckboxControl
					label={ __(
						'Force reindex (re-embed even unchanged posts)',
						'squirrel-search'
					) }
					checked={ force }
					onChange={ setForce }
				/>
			) }
			<Stack direction="row" gap="sm">
				<Button
					__next40pxDefaultSize
					variant={ dimChanged ? 'secondary' : 'primary' }
					isDestructive={ dimChanged }
					onClick={ () => void handleSubmit() }
					isBusy={ isSubmitting }
					disabled={
						isSubmitting || ( dimChanged && ! confirmRebuild )
					}
					accessibleWhenDisabled
				>
					{ __( 'Reindex all posts', 'squirrel-search' ) }
				</Button>
			</Stack>
		</Section>
	);
}
