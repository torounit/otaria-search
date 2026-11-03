import apiFetch from '@wordpress/api-fetch';
import { Button, Notice, TextControl } from '@wordpress/components';
import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { Stack, Text } from '@wordpress/ui';
import { Section } from './Section';
import { unwrapAbilityResponse } from './ability';
import type { Prefixes, UpdateSettingsResponse } from './types';

interface Props {
	prefixes: Prefixes;
	// Receives whether the document prefix changed, which requires a Reindex.
	onSaved: ( reindexRequired: boolean ) => void;
}

export function PrefixSection( { prefixes, onSaved }: Props ) {
	const [ queryPrefix, setQueryPrefix ] = useState( prefixes.query_prefix );
	const [ documentPrefix, setDocumentPrefix ] = useState(
		prefixes.document_prefix
	);
	const [ isSaving, setIsSaving ] = useState( false );
	const [ error, setError ] = useState< string | null >( null );

	async function save() {
		setIsSaving( true );
		setError( null );
		try {
			const raw = await apiFetch( {
				path: '/wp-abilities/v1/abilities/otaria-search/update-settings/run',
				method: 'POST',
				data: {
					input: {
						query_prefix: queryPrefix,
						document_prefix: documentPrefix,
					},
				},
			} );
			const saved =
				unwrapAbilityResponse< UpdateSettingsResponse >( raw );
			setQueryPrefix( saved.query_prefix );
			setDocumentPrefix( saved.document_prefix );
			onSaved( saved.reindex_required );
		} catch ( err ) {
			setError(
				( err as { message?: string } )?.message ??
					__( 'Unknown error.', 'otaria-search' )
			);
		} finally {
			setIsSaving( false );
		}
	}

	return (
		<Section title={ __( 'Prefixes', 'otaria-search' ) }>
			<Text render={ <p /> }>
				{ __(
					'Some embedding models expect a text before search terms and documents, such as "query: " and "passage: ". Check the model card and enter them exactly, including trailing spaces. Leave them empty for models that don\'t need them. They are cleared when the model changes.',
					'otaria-search'
				) }
			</Text>

			{ error && (
				<Notice status="error" onRemove={ () => setError( null ) }>
					{ error }
				</Notice>
			) }

			<TextControl
				label={ __( 'Query prefix', 'otaria-search' ) }
				help={ __(
					'Prepended to search terms. Takes effect immediately, including in the search preview.',
					'otaria-search'
				) }
				value={ queryPrefix }
				onChange={ setQueryPrefix }
			/>
			<TextControl
				label={ __( 'Document prefix', 'otaria-search' ) }
				help={ __(
					'Prepended to post content. Changing it requires a Reindex.',
					'otaria-search'
				) }
				value={ documentPrefix }
				onChange={ setDocumentPrefix }
			/>

			<Stack direction="row" gap="sm">
				<Button
					__next40pxDefaultSize
					variant="primary"
					onClick={ () => void save() }
					isBusy={ isSaving }
					disabled={ isSaving }
					accessibleWhenDisabled
				>
					{ __( 'Save prefixes', 'otaria-search' ) }
				</Button>
			</Stack>
		</Section>
	);
}
