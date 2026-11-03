import apiFetch from '@wordpress/api-fetch';
import { Button, Notice, Spinner, TextControl } from '@wordpress/components';
import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { addQueryArgs } from '@wordpress/url';
import { unwrapAbilityResponse } from './ability';
import type { PreviewResponse, PreviewResult, Thresholds } from './types';

interface Props {
	thresholds: Thresholds;
	defaults: Thresholds;
	onSaved: () => void;
}

function errorMessage( err: unknown ): string {
	return (
		( err as { message?: string } )?.message ??
		__( 'Unknown error.', 'wp-mariadb-vector-search' )
	);
}

export function ThresholdSection( { thresholds, defaults, onSaved }: Props ) {
	// Inputs are kept as strings so a half-typed value is not rewritten.
	const [ maxDistance, setMaxDistance ] = useState(
		String( thresholds.max_distance )
	);
	const [ maxRelative, setMaxRelative ] = useState(
		String( thresholds.max_relative_distance )
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

	async function save( input: Record< string, number | null > ) {
		setIsSaving( true );
		setError( null );
		try {
			const raw = await apiFetch( {
				path: '/wp-abilities/v1/abilities/wp-mariadb-vector-search/save-thresholds/run',
				method: 'POST',
				data: { input },
			} );
			const saved = unwrapAbilityResponse< Thresholds >( raw );
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
					'/wp-abilities/v1/abilities/wp-mariadb-vector-search/preview-search/run',
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
		<>
			<h2>{ __( 'Search Thresholds', 'wp-mariadb-vector-search' ) }</h2>
			<p>
				{ __(
					'Suitable values depend on the embedding model; they are reset to the defaults when the model changes. Use the preview to check them. Posts that contain the search term are still added by hybrid search, and code filters override these values.',
					'wp-mariadb-vector-search'
				) }
			</p>
			{ error && (
				<Notice status="error" onRemove={ () => setError( null ) }>
					{ error }
				</Notice>
			) }
			<TextControl
				__next40pxDefaultSize
				type="number"
				min={ 0 }
				max={ 2 }
				step={ 0.01 }
				label={ __( 'Maximum distance', 'wp-mariadb-vector-search' ) }
				help={ `${ __(
					'Posts farther than this cosine distance from the query are excluded (0 = identical, 2 = opposite). Default:',
					'wp-mariadb-vector-search'
				) } ${ defaults.max_distance }` }
				value={ maxDistance }
				onChange={ setMaxDistance }
			/>
			<TextControl
				__next40pxDefaultSize
				type="number"
				min={ 0 }
				max={ 2 }
				step={ 0.01 }
				label={ __(
					'Maximum distance behind the best match',
					'wp-mariadb-vector-search'
				) }
				help={ `${ __(
					'Posts more than this behind the closest post are excluded. 2 disables it. Default:',
					'wp-mariadb-vector-search'
				) } ${ defaults.max_relative_distance }` }
				value={ maxRelative }
				onChange={ setMaxRelative }
			/>
			<Button
				variant="primary"
				onClick={ () => void save( values ) }
				disabled={ isSaving || ! isValid }
			>
				{ isSaving ? (
					<Spinner />
				) : (
					__( 'Save thresholds', 'wp-mariadb-vector-search' )
				) }
			</Button>{ ' ' }
			<Button
				variant="secondary"
				onClick={ () =>
					void save( {
						max_distance: null,
						max_relative_distance: null,
					} )
				}
				disabled={ isSaving }
			>
				{ __( 'Reset to defaults', 'wp-mariadb-vector-search' ) }
			</Button>
			<h3>{ __( 'Preview', 'wp-mariadb-vector-search' ) }</h3>
			<form
				onSubmit={ ( event ) => {
					event.preventDefault();
					void preview();
				} }
			>
				<TextControl
					__next40pxDefaultSize
					label={ __( 'Search query', 'wp-mariadb-vector-search' ) }
					help={ __(
						'Lists the nearest posts using the thresholds above, before saving.',
						'wp-mariadb-vector-search'
					) }
					value={ query }
					onChange={ setQuery }
				/>
				<Button
					variant="secondary"
					type="submit"
					disabled={ isPreviewing || query === '' || ! isValid }
				>
					{ isPreviewing ? (
						<Spinner />
					) : (
						__( 'Preview', 'wp-mariadb-vector-search' )
					) }
				</Button>
			</form>
			{ results && results.length === 0 && (
				<p>{ __( 'No indexed posts.', 'wp-mariadb-vector-search' ) }</p>
			) }
			{ results && results.length > 0 && (
				<table className="widefat striped">
					<thead>
						<tr>
							<th>
								{ __( 'Post', 'wp-mariadb-vector-search' ) }
							</th>
							<th>
								{ __( 'Distance', 'wp-mariadb-vector-search' ) }
							</th>
							<th>
								{ __(
									'In results',
									'wp-mariadb-vector-search'
								) }
							</th>
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
										? __(
												'Yes',
												'wp-mariadb-vector-search'
											)
										: __(
												'No',
												'wp-mariadb-vector-search'
											) }
								</td>
							</tr>
						) ) }
					</tbody>
				</table>
			) }
		</>
	);
}
