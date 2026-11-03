import { Card, Stack } from '@wordpress/ui';
import type { ReactNode } from 'react';

interface Props {
	title: string;
	children: ReactNode;
}

/**
 * A titled card whose children are spaced by the design system's gap token.
 *
 * @param props          Component props.
 * @param props.title    Section heading, rendered as an h2.
 * @param props.children Section content.
 */
export function Section( { title, children }: Props ) {
	return (
		<Card.Root render={ <section /> }>
			<Card.Header>
				<Card.Title render={ <h2 /> }>{ title }</Card.Title>
			</Card.Header>
			<Card.Content>
				<Stack direction="column" gap="lg">
					{ children }
				</Stack>
			</Card.Content>
		</Card.Root>
	);
}
