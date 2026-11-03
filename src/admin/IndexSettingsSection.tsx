import apiFetch from '@wordpress/api-fetch';
import { Button, CheckboxControl, Notice } from '@wordpress/components';
import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { Stack } from '@wordpress/ui';
import { Section } from './Section';
import { unwrapAbilityResponse } from './ability';
import type { UpdateSettingsResponse } from './types';

interface Props {
	excludePasswordProtected: boolean;
	onSaved: () => void;
}

export function IndexSettingsSection( {
	excludePasswordProtected,
	onSaved,
}: Props ) {
	const [ exclude, setExclude ] = useState( excludePasswordProtected );
	const [ isSaving, setIsSaving ] = useState( false );
	const [ error, setError ] = useState< string | null >( null );

	async function save() {
		setIsSaving( true );
		setError( null );
		try {
			const raw = await apiFetch( {
				path: '/wp-abilities/v1/abilities/wp-mariadb-vector-search/update-settings/run',
				method: 'POST',
				data: { input: { exclude_password_protected: exclude } },
			} );
			setExclude(
				unwrapAbilityResponse< UpdateSettingsResponse >( raw )
					.exclude_password_protected
			);
			onSaved();
		} catch ( err ) {
			setError(
				err instanceof Error
					? err.message
					: __( 'Unknown error.', 'wp-mariadb-vector-search' )
			);
		} finally {
			setIsSaving( false );
		}
	}

	return (
		<Section title={ __( 'Indexing', 'wp-mariadb-vector-search' ) }>
			{ error && (
				<Notice status="error" onRemove={ () => setError( null ) }>
					{ error }
				</Notice>
			) }
			<CheckboxControl
				label={ __(
					'Do not index password-protected posts',
					'wp-mariadb-vector-search'
				) }
				help={ __(
					'Indexed posts are sent to the embedding provider. Run Reindex all posts to apply a change to existing posts.',
					'wp-mariadb-vector-search'
				) }
				checked={ exclude }
				onChange={ setExclude }
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
					{ __( 'Save', 'wp-mariadb-vector-search' ) }
				</Button>
			</Stack>
		</Section>
	);
}
