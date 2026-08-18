import { render, screen } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import { FieldCount } from '../FieldCount'

describe('how much room is left', () => {
  it('says nothing while there is plenty', () => {
    const { container } = render(<FieldCount value={'a'.repeat(10)} limit={100} />)

    // A counter under every box from the first keystroke reads as a target to
    // stay under, which is the opposite of what these limits are for.
    expect(container).toBeEmptyDOMElement()
  })

  it('appears as the end comes into view', () => {
    render(<FieldCount value={'a'.repeat(85)} limit={100} />)

    expect(screen.getByText('15 left')).toBeInTheDocument()
  })

  it('counts characters rather than bytes, so an emoji costs one', () => {
    // '🥹' is four bytes and two UTF-16 units; to the person typing it is one
    // character, and the count has to agree with them.
    render(<FieldCount value={'🥹'.repeat(45)} limit={50} />)

    expect(screen.getByText('5 left')).toBeInTheDocument()
  })

  it('says how far over, if something was pasted in', () => {
    render(<FieldCount value={'a'.repeat(120)} limit={100} />)

    expect(screen.getByText('20 over')).toBeInTheDocument()
  })
})
