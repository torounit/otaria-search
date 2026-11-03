import apiFetch from '@wordpress/api-fetch';
import { Button, CheckboxControl, Notice } from '@wordpress/components';
import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { Stack, Text } from '@wordpress/ui';
import { Section } from './Section';
import { unwrapAbilityResponse } from './ability';
import type { AvailablePostType, SavePostTypesResponse } from './types';

interface Props {
	availablePostTypes: AvailablePostType[];
	postTypes: string[];
	// Receives the post types that were not indexed before and need a Reindex.
	onSaved: ( added: string[] ) => void;
}

export function PostTypesSection( {
	availablePostTypes,
	postTypes,
	onSaved,
}: Props ) {
	const [ selected, setSelected ] = useState( postTypes );
	const [ isSaving, setIsSaving ] = useState( false );
	const [ error, setError ] = useState< string | null >( null );

	function toggle( name: string, checked: boolean ) {
		setSelected( ( current ) =>
			checked
				? [ ...current, name ]
				: current.filter( ( type ) => type !== name )
		);
	}

	async function save() {
		setIsSaving( true );
		setError( null );
		try {
			const raw = await apiFetch( {
				path: '/wp-abilities/v1/abilities/wp-mariadb-vector-search/save-post-types/run',
				method: 'POST',
				data: { input: { post_types: selected } },
			} );
			onSaved(
				unwrapAbilityResponse< SavePostTypesResponse >( raw ).added
			);
		} catch ( err ) {
			setError(
				( err as { message?: string } )?.message ??
					__( 'Unknown error.', 'wp-mariadb-vector-search' )
			);
		} finally {
			setIsSaving( false );
		}
	}

	return (
		<Section title={ __( 'Post Types', 'wp-mariadb-vector-search' ) }>
			<Text render={ <p /> }>
				{ __(
					'Post types that are indexed and searched. Unchecking a post type removes its posts from the index; checking one requires a Reindex.',
					'wp-mariadb-vector-search'
				) }
			</Text>

			{ error && (
				<Notice status="error" onRemove={ () => setError( null ) }>
					{ error }
				</Notice>
			) }

			{ availablePostTypes.map( ( type ) => (
				<CheckboxControl
					key={ type.name }
					label={ type.label }
					checked={ selected.includes( type.name ) }
					onChange={ ( checked ) => toggle( type.name, checked ) }
				/>
			) ) }

			<Stack direction="row" gap="sm">
				<Button
					__next40pxDefaultSize
					variant="primary"
					onClick={ () => void save() }
					isBusy={ isSaving }
					disabled={ isSaving || selected.length === 0 }
					accessibleWhenDisabled
				>
					{ __( 'Save post types', 'wp-mariadb-vector-search' ) }
				</Button>
			</Stack>
		</Section>
	);
}
