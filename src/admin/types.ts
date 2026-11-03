export interface Progress {
	done: number;
	total: number;
}

export interface Settings {
	provider: string;
	model: string;
	dimensions: number | null;
}

export interface AvailableModel {
	provider: string;
	model: string;
	label: string;
}

export interface StatusResponse {
	is_supported: boolean;
	installed: boolean;
	indexed: number;
	table_dims: number | null;
	progress: Progress | null;
	settings: Settings;
	available_models: AvailableModel[];
	dim_changed: boolean;
	thresholds: Thresholds;
	default_thresholds: Thresholds;
	hybrid: boolean;
	post_types: string[];
	available_post_types: AvailablePostType[];
	prefixes: Prefixes;
	exclude_password_protected: boolean;
}

export interface IndexSettings {
	exclude_password_protected: boolean;
}

export interface Prefixes {
	query_prefix: string;
	document_prefix: string;
}

export interface SavePrefixesResponse extends Prefixes {
	document_changed: boolean;
}

export interface AvailablePostType {
	name: string;
	label: string;
}

export interface SavePostTypesResponse {
	post_types: string[];
	added: string[];
}

export interface Thresholds {
	max_distance: number;
	max_relative_distance: number;
}

export interface SearchSettings extends Thresholds {
	hybrid: boolean;
}

export interface PreviewResult {
	post_id: number;
	title: string;
	distance: number;
	included: boolean;
}

export interface PreviewResponse {
	results: PreviewResult[];
}

export interface ReindexResponse {
	rebuilt: boolean;
}
