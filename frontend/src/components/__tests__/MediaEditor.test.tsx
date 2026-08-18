import { render, screen, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { describe, expect, it, vi } from 'vitest'
import { MediaEditor, type EditorItem } from '../MediaEditor'
import { anImage, aVideo } from '../../test/harness'

function existing(id: string): EditorItem {
  return { kind: 'existing', key: id, media: anImage({ id }) }
}

function tiles() {
  return screen.getAllByRole('listitem').filter((li) => li.className.includes('__tile'))
}

function setup(items: EditorItem[], removing = new Set<string>()) {
  const onToggleRemove = vi.fn()
  const onMove = vi.fn()

  render(
    <MediaEditor
      items={items}
      removing={removing}
      onToggleRemove={onToggleRemove}
      onMove={onMove}
      onAdd={vi.fn()}
    />,
  )

  return { onToggleRemove, onMove }
}

describe('arranging a memory’s photographs', () => {
  it('marks the first as the one the timeline will show', () => {
    setup([existing('a'), existing('b'), existing('c')])

    const [first, second] = tiles()

    // The rule is stated on the thing it applies to. Left to be inferred from
    // position, it stops being inferrable the moment the strip wraps.
    expect(within(first).getByText('Cover')).toBeInTheDocument()
    expect(within(second).queryByText('Cover')).not.toBeInTheDocument()
  })

  it('removes by marking, so nothing is destroyed by a stray click', async () => {
    const { onToggleRemove } = setup([existing('a'), existing('b')])

    await userEvent.click(screen.getAllByRole('button', { name: /^Remove/ })[0])

    expect(onToggleRemove).toHaveBeenCalledWith('a')
  })

  it('offers the way back on the tile that was marked', () => {
    setup([existing('a'), existing('b')], new Set(['a']))

    const [first] = tiles()

    expect(within(first).getByText('Removing')).toBeInTheDocument()
    expect(within(first).getByRole('button', { name: /^Keep/ })).toBeInTheDocument()
  })

  it('passes over a marked photograph when deciding the cover', () => {
    setup([existing('a'), existing('b'), existing('c')], new Set(['a']))

    const [first, second] = tiles()

    expect(within(first).queryByText('Cover')).not.toBeInTheDocument()
    expect(within(second).getByText('Cover')).toBeInTheDocument()
  })

  it('moves a photograph with the arrows, which work without a pointer', async () => {
    const { onMove } = setup([existing('a'), existing('b'), existing('c')])

    await userEvent.click(screen.getAllByRole('button', { name: /Move .* earlier/ })[1])

    expect(onMove).toHaveBeenCalledWith('b', 0)
  })

  it('will not move the first earlier or the last later', () => {
    setup([existing('a'), existing('b')])

    const earlier = screen.getAllByRole('button', { name: /Move .* earlier/ })
    const later = screen.getAllByRole('button', { name: /Move .* later/ })

    expect(earlier[0]).toBeDisabled()
    expect(later[1]).toBeDisabled()
  })

  it('counts what will remain, not what is on screen', () => {
    setup([existing('a'), existing('b'), existing('c')], new Set(['b']))

    expect(screen.getByText('2 files · 1 to remove')).toBeInTheDocument()
  })

  it('says plainly when nothing would be left', () => {
    setup([existing('a')], new Set(['a']))

    expect(screen.getByRole('alert')).toHaveTextContent(
      'A memory has to keep at least one photo or video.',
    )
  })

  it('shows a video by its own preview rather than a broken image', () => {
    const { container } = render(
      <MediaEditor
        items={[{ kind: 'existing', key: 'v', media: aVideo({ id: 'v' }) }]}
        removing={new Set()}
        onToggleRemove={vi.fn()}
        onMove={vi.fn()}
        onAdd={vi.fn()}
      />,
    )

    // A video carries a poster, not a thumb; asking for the wrong one is how
    // the strip fills up with broken icons.
    expect(container.querySelector('.mediaedit__image')).toHaveAttribute(
      'src',
      aVideo({ id: 'v' }).urls.poster,
    )
  })
})
