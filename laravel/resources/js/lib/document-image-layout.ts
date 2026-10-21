const imageLayoutParts = ['x_px', 'y_px', 'width_px', 'height_px', 'object_fit', 'rotation_deg', 'lock_aspect', 'crop_x', 'crop_y'] as const

/** Keep editor slots and the persisted image-id map in the same transaction. */
export function synchronizeImageLayouts(settings: Record<string, string>, images: readonly { id: string | number }[]): Record<string, string> {
  const layouts: Record<string, Record<string, string>> = {}
  for (const [index, image] of images.slice(0, 4).entries()) {
    const layout: Record<string, string> = {}
    for (const part of imageLayoutParts) {
      const value = settings[`image_${index + 1}_${part}`]
      if (value !== undefined) layout[part] = value
    }
    layouts[String(image.id)] = layout
  }
  return { ...settings, image_layout_by_id: JSON.stringify(layouts) }
}

/** Explicitly repair a piled-up layout without changing the images themselves. */
export function arrangeImageLayouts(settings: Record<string, string>, images: readonly { id: string | number }[]): Record<string, string> {
  if (!images.length) return settings
  const landscape = settings.paper_orientation === 'landscape'
  const width = (landscape ? 1123 : 794) - Number(settings.pdf_margin_left_px || 94) - Number(settings.pdf_margin_right_px || 76)
  const height = (landscape ? 794 : 1123) - Number(settings.pdf_margin_top_px || 57) - Number(settings.pdf_margin_bottom_px || 38) - 54
  const columns = Math.min(2, images.length)
  const rows = Math.ceil(Math.min(4, images.length) / columns)
  const gap = 16
  const itemWidth = Math.floor((width - (columns - 1) * gap) / columns)
  const itemHeight = Math.floor(Math.min(itemWidth * 0.75, (height - 8 - (rows - 1) * gap) / rows))
  const next: Record<string, string> = { ...settings, image_layout_mode: 'free' }
  for (const [index] of images.slice(0, 4).entries()) {
    const prefix = `image_${index + 1}_`
    next[`${prefix}x_px`] = String((index % columns) * (itemWidth + gap))
    next[`${prefix}y_px`] = String(8 + Math.floor(index / columns) * (itemHeight + gap))
    next[`${prefix}width_px`] = String(itemWidth)
    next[`${prefix}height_px`] = String(itemHeight)
  }
  return synchronizeImageLayouts(next, images)
}
