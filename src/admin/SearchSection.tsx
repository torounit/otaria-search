import apiFetch from '@wordpress/api-fetch';
import {
	Button,
	Notice,
	TextControl,
	ToggleControl,
} from '@wordpress/components';
import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { Stack, Text } from '@wordpress/ui';
import { addQueryArgs } from '@wordpress/url';
import { Section } from './Section';
import { unwrapAbilityResponse } from './ability';
import type {
	PreviewResponse,
	PreviewResult,
	SearchSettings,
	Thresholds,
	UpdateSettingsResponse,
} from './types';

interface Props {
	settings: SearchSettings;
	defaults: Thresholds;
	onSaved: () => void;
}

function errorMessage( err: unknown ): string {
	return (
		( err as { message?: string } )?.message ??
		__( 'Unknown error.', 'squirrel-search' )
	);
}

export function SearchSection( { settings, defaults, onSaved }: Props ) {
	const [ hybrid, setHybrid ] = useState( settings.hybrid );
	// Inputs are kept as strings so a half-typed value is not rewritten.
	const [ maxDistance, setMaxDistance ] = useState(
		String( settings.max_distance )
	);
	const [ maxRelative, setMaxRelative ] = useState(
		String( settings.max_relative_distance )
	);
	const [ isSaving, setIsSaving ] = useState( false );
	const [ error, setError ] = useState< string | null >( null );

	const [ query, setQuery ] = useState( '' );
	const [ isPreviewing, setIsPreviewing ] = useState( false );
	const [ results, setResults ] = useState< PreviewResult[] | null >( null );

	const values = {
		max_distance: Number( maxDistance ),
		max_relative_distance: Number( maxRelative ),
	};
	const isValid = [ maxDistance, maxRelative ].every(
		( v ) => v !== '' && Number( v ) >= 0 && Number( v ) <= 2
	);

	async function save( input: Record< string, number | boolean | null > ) {
		setIsSaving( true );
		setError( null );
		try {
			const raw = await apiFetch( {
				path: '/wp-abilities/v1/abilities/squirrel-search/update-settings/run',
				method: 'POST',
				data: { input },
			} );
			const saved =
				unwrapAbilityResponse< UpdateSettingsResponse >( raw );
			setHybrid( saved.hybrid );
			setMaxDistance( String( saved.max_distance ) );
			setMaxRelative( String( saved.max_relative_distance ) );
			onSaved();
		} catch ( err ) {
			setError( errorMessage( err ) );
		} finally {
			setIsSaving( false );
		}
	}

	async function preview() {
		setIsPreviewing( true );
		setError( null );
		try {
			// preview-search is read-only, so the Abilities API requires GET.
			const raw = await apiFetch( {
				path: addQueryArgs(
					'/wp-abilities/v1/abilities/squirrel-search/preview-search/run',
					{ input: { query, ...values } }
				),
			} );
			setResults(
				unwrapAbilityResponse< PreviewResponse >( raw ).results ?? []
			);
		} catch ( err ) {
			setError( errorMessage( err ) );
		} finally {
			setIsPreviewing( false );
		}
	}

	return (
		<Section title={ __( 'Search', 'squirrel-search' ) }>
			{ error && (
				<Notice status="error" onRemove={ () => setError( null ) }>
					{ error }
				</Notice>
			) }

			<ToggleControl
				label={ __( 'Hybrid search', 'squirrel-search' ) }
				help={ __(
					"Also include posts that WordPress's standard search finds (posts containing the search term), ranked together with the vector results. Catches exact terms such as product codes and names.",
					'squirrel-search'
				) }
				checked={ hybrid }
				onChange={ setHybrid }
			/>

			<Text variant="heading-md" render={ <h3 /> }>
				{ __( 'Thresholds', 'squirrel-search' ) }
			</Text>
			<Text render={ <p /> }>
				{ hybrid
					? __(
							'Suitable values depend on the embedding model; they are reset to the defaults when the model changes. Use the preview to check them. The thresholds apply to the vector results; posts that contain the search term are still added by hybrid search.',
							'squirrel-search'
						)
					: __(
							'Suitable values depend on the embedding model; they are reset to the defaults when the model changes. Use the preview to check them.',
							'squirrel-search'
						) }
			</Text>

			<TextControl
				type="number"
				min={ 0 }
				max={ 2 }
				step={ 0.01 }
				label={ __( 'Maximum distance', 'squirrel-search' ) }
				help={ `${ __(
					'Posts farther than this cosine distance from the query are excluded (0 = identical, 2 = opposite). Default:',
					'squirrel-search'
				) } ${ defaults.max_distance }` }
				value={ maxDistance }
				onChange={ setMaxDistance }
			/>
			<TextControl
				type="number"
				min={ 0 }
				max={ 2 }
				step={ 0.01 }
				label={ __(
					'Maximum distance behind the best match',
					'squirrel-search'
				) }
				help={ `${ __(
					'Posts more than this behind the closest post are excluded. 2 disables it. Default:',
					'squirrel-search'
				) } ${ defaults.max_relative_distance }` }
				value={ maxRelative }
				onChange={ setMaxRelative }
			/>
			<Stack direction="row" gap="sm" wrap="wrap">
				<Button
					__next40pxDefaultSize
					variant="primary"
					onClick={ () => void save( { ...values, hybrid } ) }
					isBusy={ isSaving }
					disabled={ isSaving || ! isValid }
					accessibleWhenDisabled
				>
					{ __( 'Save', 'squirrel-search' ) }
				</Button>
				<Button
					__next40pxDefaultSize
					variant="secondary"
					onClick={ () =>
						void save( {
							max_distance: null,
							max_relative_distance: null,
						} )
					}
					disabled={ isSaving }
					accessibleWhenDisabled
				>
					{ __( 'Reset thresholds to defaults', 'squirrel-search' ) }
				</Button>
			</Stack>

			<Stack
				direction="column"
				gap="md"
				render={
					<form
						onSubmit={ ( event ) => {
							event.preventDefault();
							void preview();
						} }
					/>
				}
			>
				<Text variant="heading-md" render={ <h3 /> }>
					{ __( 'Preview', 'squirrel-search' ) }
				</Text>
				<TextControl
					label={ __( 'Search query', 'squirrel-search' ) }
					help={ __(
						'Lists the nearest posts using the thresholds above, before saving.',
						'squirrel-search'
					) }
					value={ query }
					onChange={ setQuery }
				/>
				<Stack direction="row" gap="sm">
					<Button
						__next40pxDefaultSize
						variant="secondary"
						type="submit"
						isBusy={ isPreviewing }
						disabled={ isPreviewing || query === '' || ! isValid }
						accessibleWhenDisabled
					>
						{ __( 'Preview', 'squirrel-search' ) }
					</Button>
				</Stack>
			</Stack>

			{ results && results.length === 0 && (
				<Text render={ <p /> }>
					{ __( 'No indexed posts.', 'squirrel-search' ) }
				</Text>
			) }
			{ results && results.length > 0 && (
				<table className="widefat striped">
					<thead>
						<tr>
							<th>{ __( 'Post', 'squirrel-search' ) }</th>
							<th>{ __( 'Distance', 'squirrel-search' ) }</th>
							<th>{ __( 'In results', 'squirrel-search' ) }</th>
						</tr>
					</thead>
					<tbody>
						{ results.map( ( result ) => (
							<tr
								key={ result.post_id }
								style={
									result.included
										? undefined
										: { opacity: 0.55 }
								}
							>
								<td>{ result.title }</td>
								<td>{ result.distance.toFixed( 3 ) }</td>
								<td>
									{ result.included
										? __( 'Yes', 'squirrel-search' )
										: __( 'No', 'squirrel-search' ) }
								</td>
							</tr>
						) ) }
					</tbody>
				</table>
			) }
		</Section>
	);
}
