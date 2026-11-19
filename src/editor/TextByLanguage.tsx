/**
 * A text in each language: the default text, and a full list of runs for every
 * other language of the site (the order of the fields changes between languages).
 * A language with no variant shows the default text. Shown only when the site has
 * more than one language.
 */
import { Button, SelectControl } from '@wordpress/components';
import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import FieldText from './FieldText';
import { runsOf, type Run, type TextEl } from './model';

type Props = {
	el: TextEl;
	sample: Record< string, string >;
	onChange: ( patch: Partial< TextEl > ) => void;
};

export default function TextByLanguage( { el, sample, onChange }: Props ) {
	const locales = ( window as unknown as { evcertEditor?: { locales?: { locale: string; name: string }[] } } ).evcertEditor?.locales || [];
	const [ lang, setLang ] = useState( '' );
	if ( locales.length < 2 ) {
		return null;
	}
	const variants = el.variants || {};
	const set = ( locale: string, runs: Run[] | null ) => {
		const next = { ...variants };
		if ( runs && runs.length ) {
			next[ locale ] = runs;
		} else {
			delete next[ locale ];
		}
		onChange( { variants: Object.keys( next ).length ? next : undefined } );
	};

	return (
		<div className="evcert-inspector__section evcert-text-by-language">
			<SelectControl
				__next40pxDefaultSize
				__nextHasNoMarginBottom
				label={ __( 'Text by language', 'events-certificates' ) }
				help={ __( 'A language with no text of its own shows the default text.', 'events-certificates' ) }
				value={ lang }
				options={ [
					{ label: __( 'Default text', 'events-certificates' ), value: '' },
					...locales.map( ( l ) => ( { label: ( variants[ l.locale ]?.length ? '• ' : '' ) + l.name, value: l.locale } ) ),
				] }
				onChange={ setLang }
			/>
			{ lang && (
				<>
					<FieldText
						label={ sprintf( /* translators: %s: language name. */ __( 'Text in %s', 'events-certificates' ), locales.find( ( l ) => l.locale === lang )?.name || lang ) }
						runs={ variants[ lang ] || runsOf( el ) }
						onChange={ ( runs ) => set( lang, runs ) }
						sample={ sample }
						locale={ lang.replace( '_', '-' ) }
					/>
					{ variants[ lang ] && (
						<Button variant="link" isDestructive onClick={ () => set( lang, null ) }>
							{ __( 'Use the default text', 'events-certificates' ) }
						</Button>
					) }
				</>
			) }
		</div>
	);
}
