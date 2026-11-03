import { __ } from '@wordpress/i18n';
import { Section } from './Section';
import type { Progress, StatusResponse } from './types';

interface Props {
	isSupported: StatusResponse[ 'is_supported' ];
	installed: StatusResponse[ 'installed' ];
	indexed: StatusResponse[ 'indexed' ];
	tableDims: StatusResponse[ 'table_dims' ];
	progress: Progress | null;
}

export function StatusSection( {
	isSupported,
	installed,
	indexed,
	tableDims,
	progress,
}: Props ) {
	return (
		<Section title={ __( 'Status', 'otaria-search' ) }>
			<table className="widefat striped">
				<tbody>
					<tr>
						<td>
							{ __( 'MariaDB VECTOR support', 'otaria-search' ) }
						</td>
						<td>
							{ isSupported ? (
								<span style={ { color: 'green' } }>
									{ '✓ ' }
									{ __( 'Available', 'otaria-search' ) }
								</span>
							) : (
								<span style={ { color: 'red' } }>
									{ '✗ ' }
									{ __(
										'Not available (MariaDB 11.7+ required)',
										'otaria-search'
									) }
								</span>
							) }
						</td>
					</tr>
					<tr>
						<td>{ __( 'Indexed posts', 'otaria-search' ) }</td>
						<td>{ installed ? indexed : '—' }</td>
					</tr>
					<tr>
						<td>{ __( 'Table dimensions', 'otaria-search' ) }</td>
						<td>{ tableDims ?? '—' }</td>
					</tr>
					<tr>
						<td>{ __( 'Backfill status', 'otaria-search' ) }</td>
						<td>
							{ progress
								? `${ progress.done } / ${ progress.total } posts`
								: __( 'Idle', 'otaria-search' ) }
						</td>
					</tr>
				</tbody>
			</table>
		</Section>
	);
}
