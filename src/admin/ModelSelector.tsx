import apiFetch from '@wordpress/api-fetch';
import { Button, Notice, SelectControl } from '@wordpress/components';
import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { Stack, Text } from '@wordpress/ui';
import { Section } from './Section';
import { unwrapAbilityResponse } from './ability';
import type { AvailableModel, Settings } from './types';

interface Props {
	availableModels: AvailableModel[];
	currentProvider: string;
	currentModel: string;
	currentDims: number | null;
	// Dimensions of the existing embeddings table, or null when not installed.
	tableDims: number | null;
	onSaved: ( dims: number, needRebuild: boolean ) => void;
}

export function ModelSelector( {
	availableModels,
	currentProvider,
	currentModel,
	currentDims,
	tableDims,
	onSaved,
}: Props ) {
	const currentValue =
		currentProvider && currentModel
			? `${ currentProvider }:${ currentModel }`
			: '';

	const [ selected, setSelected ] = useState( currentValue );
	const [ isSaving, setIsSaving ] = useState( false );
	const [ error, setError ] = useState< string | null >( null );

	const title = __( 'Embedding Model', 'wp-mariadb-vector-search' );

	if ( availableModels.length === 0 ) {
		return (
			<Section title={ title }>
				<Text render={ <p /> }>
					{ __(
						'No embedding models available. Configure an embedding-capable AI provider in Settings › Connectors.',
						'wp-mariadb-vector-search'
					) }
				</Text>
			</Section>
		);
	}

	const options = availableModels.map( ( m ) => ( {
		value: `${ m.provider }:${ m.model }`,
		label: `[${ m.provider }] ${ m.label }`,
	} ) );

	// Without a placeholder, a <select> with value="" displays the first
	// option by default, making it look selected even though `selected`
	// is still "" — which keeps the Save button disabled.
	if ( currentValue === '' ) {
		options.unshift( {
			value: '',
			label: __( 'Select a model…', 'wp-mariadb-vector-search' ),
		} );
	}

	async function handleSave() {
		const colon = selected.indexOf( ':' );
		if ( colon < 0 ) {
			return;
		}

		setIsSaving( true );
		setError( null );

		try {
			// The ability probes the model to detect its dimensions and saves
			// provider, model and dimensions together.
			const raw = await apiFetch( {
				path: '/wp-abilities/v1/abilities/wp-mariadb-vector-search/select-model/run',
				method: 'POST',
				data: {
					input: {
						provider: selected.slice( 0, colon ),
						model: selected.slice( colon + 1 ),
					},
				},
			} );
			const dims =
				unwrapAbilityResponse< Settings >( raw ).dimensions ?? 0;

			// A rebuild is needed only when an existing table has other
			// dimensions; a missing table is created at the saved dimensions.
			onSaved( dims, tableDims !== null && dims !== tableDims );
		} catch ( err ) {
			const message =
				( err as { message?: string } )?.message ??
				__( 'Unknown error.', 'wp-mariadb-vector-search' );
			setError( message );
		} finally {
			setIsSaving( false );
		}
	}

	return (
		<Section title={ title }>
			{ error && (
				<Notice status="error" onRemove={ () => setError( null ) }>
					{ error }
				</Notice>
			) }
			<SelectControl
				__next40pxDefaultSize
				__nextHasNoMarginBottom
				label={ __( 'Model', 'wp-mariadb-vector-search' ) }
				value={ selected }
				options={ options }
				onChange={ setSelected }
				help={
					currentDims
						? `${ __(
								'Current embedding dimensions:',
								'wp-mariadb-vector-search'
							) } ${ currentDims }. ${ __(
								'Saving sends a short probe request to the model to detect its dimensions.',
								'wp-mariadb-vector-search'
							) }`
						: __(
								'Saving sends a short probe request to the model to detect its dimensions.',
								'wp-mariadb-vector-search'
							)
				}
			/>
			<Stack direction="row" gap="sm">
				<Button
					__next40pxDefaultSize
					variant="primary"
					onClick={ () => void handleSave() }
					isBusy={ isSaving }
					disabled={ isSaving || selected === '' }
					accessibleWhenDisabled
				>
					{ __( 'Save model', 'wp-mariadb-vector-search' ) }
				</Button>
			</Stack>
		</Section>
	);
}
