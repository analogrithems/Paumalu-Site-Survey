/**
 * The repeatable panel: main panel plus any number of subpanels.
 *
 * Panel-scoped catalog sections are rendered once per panel, so adding a subpanel needs no bespoke
 * question set — it is the same "Interior of Load Center" and electrical readings, answered again.
 */

import ItemRow from './ItemRow';

function Readings( { readings, values, onChange } ) {
	return (
		<div className="pe-readings">
			{ readings.map( ( reading ) => (
				<label key={ reading.key } className="pe-field pe-field--number">
					<span className="pe-field__label">{ reading.label }</span>
					<span className="pe-field__control">
						<input
							type="number"
							// inputMode decimal gets the numeric keypad with a decimal point on iOS,
							// which type=number alone does not reliably do.
							inputMode="decimal"
							step="0.1"
							min={ reading.min }
							max={ reading.max }
							value={ values?.[ reading.key ] ?? '' }
							onChange={ ( event ) =>
								onChange( reading.key, event.target.value )
							}
						/>
						{ !! reading.unit && <span className="pe-field__unit">{ reading.unit }</span> }
					</span>
				</label>
			) ) }
		</div>
	);
}

export default function PanelEditor( {
	panels,
	activeIndex,
	sections,
	onSelect,
	onAdd,
	onRemove,
	onPanelChange,
	onAnswerChange,
	onReadingChange,
} ) {
	const panel = panels[ activeIndex ];

	if ( ! panel ) {
		return null;
	}

	return (
		<div className="pe-panels">
			<div className="pe-panels__tabs" role="tablist">
				{ panels.map( ( item, index ) => (
					<button
						key={ item.id }
						type="button"
						role="tab"
						aria-selected={ index === activeIndex }
						className={ `pe-tab ${ index === activeIndex ? 'is-active' : '' }` }
						onClick={ () => onSelect( index ) }
					>
						{ item.label || `Panel ${ index + 1 }` }
					</button>
				) ) }
				<button type="button" className="pe-tab pe-tab--add" onClick={ onAdd }>
					{ '+ Subpanel' }
				</button>
			</div>

			<div className="pe-panel">
				<div className="pe-grid">
					<label className="pe-field">
						<span className="pe-field__label">{ 'Panel name' }</span>
						<input
							type="text"
							value={ panel.label || '' }
							onChange={ ( event ) => onPanelChange( 'label', event.target.value ) }
						/>
					</label>
					<label className="pe-field">
						<span className="pe-field__label">{ 'Location' }</span>
						<input
							type="text"
							value={ panel.location || '' }
							placeholder="Garage, north wall"
							onChange={ ( event ) => onPanelChange( 'location', event.target.value ) }
						/>
					</label>
					<label className="pe-field">
						<span className="pe-field__label">{ 'Brand' }</span>
						<input
							type="text"
							value={ panel.brand || '' }
							onChange={ ( event ) => onPanelChange( 'brand', event.target.value ) }
						/>
					</label>
					<label className="pe-field">
						<span className="pe-field__label">{ 'Model' }</span>
						<input
							type="text"
							value={ panel.model || '' }
							onChange={ ( event ) => onPanelChange( 'model', event.target.value ) }
						/>
					</label>
					<label className="pe-field">
						<span className="pe-field__label">{ 'Rating (A)' }</span>
						<input
							type="text"
							inputMode="numeric"
							value={ panel.amps || '' }
							onChange={ ( event ) => onPanelChange( 'amps', event.target.value ) }
						/>
					</label>
				</div>

				{ sections.map( ( section ) => (
					<section key={ section.key } className="pe-section">
						<h3 className="pe-section__title">{ section.label }</h3>

						{ section.items.map( ( item ) => (
							<ItemRow
								key={ item.key }
								item={ item }
								polarity={ section.polarity }
								panelId={ panel.id }
								answer={ panel.items?.[ item.key ] }
								onChange={ ( answer ) => onAnswerChange( item.key, answer ) }
							/>
						) ) }

						{ !! section.readings?.length && (
							<Readings
								readings={ section.readings }
								values={ panel.readings }
								onChange={ onReadingChange }
							/>
						) }
					</section>
				) ) }

				{ activeIndex > 0 && (
					<button
						type="button"
						className="pe-btn pe-btn--danger-quiet"
						onClick={ () => onRemove( activeIndex ) }
					>
						{ 'Remove this subpanel' }
					</button>
				) }
			</div>
		</div>
	);
}
