import apiFetch from '@wordpress/api-fetch';
import { Notice, Spinner } from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { Page } from '@wordpress/admin-ui';
import { Stack } from '@wordpress/ui';
import { IndexSettingsSection } from './IndexSettingsSection';
import { ModelSelector } from './ModelSelector';
import { PostTypesSection } from './PostTypesSection';
import { PrefixSection } from './PrefixSection';
import { ReindexSection } from './ReindexSection';
import { SearchSection } from './SearchSection';
import { StatusSection } from './StatusSection';
import { unwrapAbilityResponse } from './ability';
import type { StatusResponse, Thresholds } from './types';

// Mirrors Settings::DEFAULT_THRESHOLDS, for responses that lack them.
const DEFAULT_THRESHOLDS: Thresholds = {
	max_distance: 0.65,
};

type AppStatus =
	| { phase: 'loading' }
	| { phase: 'error'; message: string }
	| { phase: 'ready'; data: StatusResponse };

function normalizeStatusResponse( raw: unknown ): StatusResponse {
	const resolved = unwrapAbilityResponse< StatusResponse | undefined >( raw );

	return {
		is_supported: resolved?.is_supported ?? false,
		installed: resolved?.installed ?? false,
		indexed: resolved?.indexed ?? 0,
		table_dims: resolved?.table_dims ?? null,
		progress: resolved?.progress || null,
		settings: {
			provider: resolved?.settings?.provider ?? '',
			model: resolved?.settings?.model ?? '',
			dimensions: resolved?.settings?.dimensions ?? null,
		},
		available_models: resolved?.available_models ?? [],
		dim_changed: resolved?.dim_changed ?? false,
		thresholds: resolved?.thresholds ?? DEFAULT_THRESHOLDS,
		default_thresholds: resolved?.default_thresholds ?? DEFAULT_THRESHOLDS,
		hybrid: resolved?.hybrid ?? true,
		exclude_password_protected:
			resolved?.exclude_password_protected ?? true,
		post_types: resolved?.post_types ?? [],
		available_post_types: resolved?.available_post_types ?? [],
		prefixes: {
			query_prefix: resolved?.prefixes?.query_prefix ?? '',
			document_prefix: resolved?.prefixes?.document_prefix ?? '',
		},
	};
}

export function VectorSearchApp() {
	const [ status, setStatus ] = useState< AppStatus >( { phase: 'loading' } );
	const [ successMessage, setSuccessMessage ] = useState< string | null >(
		null
	);

	async function loadStatus() {
		setStatus( { phase: 'loading' } );
		try {
			const data = await apiFetch( {
				path: '/wp-abilities/v1/abilities/otaria-search/get-status/run',
			} );
			setStatus( {
				phase: 'ready',
				data: normalizeStatusResponse( data ),
			} );
		} catch ( err ) {
			const message =
				err instanceof Error
					? err.message
					: __( 'Failed to load status.', 'otaria-search' );
			setStatus( { phase: 'error', message } );
		}
	}

	useEffect( () => {
		void loadStatus();
	}, [] );

	return (
		<Page hasPadding title={ __( 'Otaria Search', 'otaria-search' ) }>
			<Stack direction="column" gap="xl">
				{ successMessage && (
					<Notice
						status="success"
						onRemove={ () => setSuccessMessage( null ) }
					>
						{ successMessage }
					</Notice>
				) }

				{ status.phase === 'loading' && <Spinner /> }

				{ status.phase === 'error' && (
					<Notice status="error" isDismissible={ false }>
						{ status.message }
					</Notice>
				) }

				{ status.phase === 'ready' && (
					<>
						<StatusSection
							isSupported={ status.data.is_supported }
							installed={ status.data.installed }
							indexed={ status.data.indexed }
							tableDims={ status.data.table_dims }
							progress={ status.data.progress }
						/>

						<ModelSelector
							availableModels={ status.data.available_models }
							currentProvider={ status.data.settings.provider }
							currentModel={ status.data.settings.model }
							currentDims={ status.data.settings.dimensions }
							tableDims={ status.data.table_dims }
							onSaved={ ( _dims, needRebuild ) => {
								setSuccessMessage(
									needRebuild
										? __(
												'Model saved. The table dimension has changed — please run Reindex all posts to recreate the table.',
												'otaria-search'
											)
										: __(
												'Model saved. Please run Reindex all posts to apply the new model.',
												'otaria-search'
											)
								);
								void loadStatus();
							} }
						/>

						{ status.data.settings.model !== '' && (
							<PrefixSection
								// Remount after a model change clears the prefixes.
								key={ `${ status.data.settings.provider }:${ status.data.settings.model }` }
								prefixes={ status.data.prefixes }
								onSaved={ ( reindexRequired ) =>
									setSuccessMessage(
										reindexRequired
											? __(
													'Prefixes saved. Please run Reindex all posts to re-embed posts with the new document prefix.',
													'otaria-search'
												)
											: __(
													'Prefixes saved.',
													'otaria-search'
												)
									)
								}
							/>
						) }

						{ status.data.installed && (
							<SearchSection
								// Remount after a model change resets the thresholds.
								key={ `${ status.data.settings.provider }:${ status.data.settings.model }` }
								settings={ {
									...status.data.thresholds,
									hybrid: status.data.hybrid,
								} }
								defaults={ status.data.default_thresholds }
								onSaved={ () =>
									setSuccessMessage(
										__(
											'Search settings saved.',
											'otaria-search'
										)
									)
								}
							/>
						) }

						<PostTypesSection
							availablePostTypes={
								status.data.available_post_types
							}
							postTypes={ status.data.post_types }
							onSaved={ ( reindexRequired ) => {
								setSuccessMessage(
									reindexRequired
										? __(
												'Post types saved. Please run Reindex all posts to index the added post types.',
												'otaria-search'
											)
										: __(
												'Post types saved.',
												'otaria-search'
											)
								);
								void loadStatus();
							} }
						/>

						<IndexSettingsSection
							excludePasswordProtected={
								status.data.exclude_password_protected
							}
							onSaved={ () =>
								setSuccessMessage(
									__(
										'Index settings saved. Run Reindex all posts to apply them to existing posts.',
										'otaria-search'
									)
								)
							}
						/>

						<ReindexSection
							isSupported={ status.data.is_supported }
							installed={ status.data.installed }
							dimChanged={ status.data.dim_changed }
							curDims={ status.data.settings.dimensions }
							tableDims={ status.data.table_dims }
							onReindexed={ ( rebuilt ) => {
								setSuccessMessage(
									rebuilt
										? __(
												'Table rebuilt. Reindex has been scheduled.',
												'otaria-search'
											)
										: __(
												'Backfill has been scheduled.',
												'otaria-search'
											)
								);
								void loadStatus();
							} }
						/>
					</>
				) }
			</Stack>
		</Page>
	);
}
