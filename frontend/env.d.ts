/// <reference types="vite/client" />

declare module '*.css?inline' {
  const content: string
  export default content
}

interface Window {
  claireEmbed?: (config?: ClaireEmbedConfig) => Promise<HTMLElement>
  destroyClaireEmbed?: () => void
}

interface ClaireEmbedConfig {
  baseUrl?: string
  target?: string | Element
  token?: string
  sessionToken?: string
  ssoToken?: string
  ssoTokenType?: string
}
