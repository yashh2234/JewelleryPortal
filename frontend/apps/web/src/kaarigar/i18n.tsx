import { createContext, useContext, useState, type ReactNode } from 'react'

/** Hindi and English first; Bengali and Gujarati get added as tenants need them. */
export type Lang = 'hi' | 'en'

const dict = {
  appName: { hi: 'कारीगर', en: 'Kaarigar' },
  goldInHand: { hi: 'आपके पास सोना', en: 'Gold in hand' },
  fine: { hi: 'फाइन', en: 'Fine' },
  gross: { hi: 'ग्रॉस', en: 'Gross' },
  jobsToday: { hi: 'आज देने वाले काम', en: 'Jobs due today' },
  openJobs: { hi: 'चालू काम', en: 'Open jobs' },
  labourWeek: { hi: 'इस हफ़्ते की मज़दूरी', en: 'Labour this week' },
  newIssues: { hi: 'नया सोना मिला', en: 'New gold issued' },
  myJobs: { hi: 'मेरे काम', en: 'My jobs' },
  khata: { hi: 'सोना खाता', en: 'Gold khata' },
  earnings: { hi: 'कमाई', en: 'Earnings' },
  requests: { hi: 'मेरी अर्ज़ियाँ', en: 'My requests' },
  comingSoon: { hi: 'जल्द आ रहा है', en: 'Coming soon' },
  logout: { hi: 'लॉगआउट', en: 'Log out' },
  hello: { hi: 'नमस्ते', en: 'Hello' },
  back: { hi: 'वापस', en: 'Back' },
  offline: { hi: 'इंटरनेट नहीं है — पुरानी जानकारी दिख रही है', en: 'Offline — showing last synced data' },
  queued: { hi: 'भेजना बाकी', en: 'Waiting to send' },
  accept: { hi: 'सही है, मिल गया', en: 'Accept' },
  dispute: { hi: 'गलत है', en: 'Dispute' },
  disputeReason: { hi: 'क्या गलत है?', en: 'What is wrong?' },
  weightGot: { hi: 'आपको कितना मिला (g)', en: 'Weight you got (g)' },
  send: { hi: 'भेजें', en: 'Send' },
  noIssues: { hi: 'कोई नया सोना नहीं', en: 'No new issues' },
  noJobs: { hi: 'कोई चालू काम नहीं', en: 'No open jobs' },
  due: { hi: 'देना है', en: 'Due' },
  holding: { hi: 'आपके पास', en: 'Holding' },
  started: { hi: 'शुरू किया', en: 'Started' },
  ready: { hi: 'तैयार है', en: 'Ready' },
  returnWork: { hi: 'काम वापस करें', en: 'Return work' },
  workWeight: { hi: 'काम का वज़न (g)', en: 'Work weight (g)' },
  stoneWeight: { hi: 'नग का वज़न (g)', en: 'Stone weight (g)' },
  scrapWeight: { hi: 'कचरा / बुरादा (g)', en: 'Scrap / dust (g)' },
  pieces: { hi: 'नग (pieces)', en: 'Pieces' },
  photoWork: { hi: 'काम की फ़ोटो', en: 'Photo of work' },
  photoScale: { hi: 'काँटे की फ़ोटो', en: 'Photo of scale' },
  note: { hi: 'कुछ कहना है?', en: 'Note' },
  returnSent: { hi: 'भेज दिया। दुकान तौल कर चढ़ाएगी।', en: 'Sent. The store will weigh and post it.' },
  returnPending: { hi: 'वापसी जाँच में है', en: 'Return waiting for store' },
  storeWeighs: { hi: 'दुकान फिर से तौलेगी; वही वज़न खाते में चढ़ेगा।', en: 'The store weighs it again; that weight goes in your khata.' },
  issued: { hi: 'मिला', en: 'Issued' },
  returned: { hi: 'वापस', en: 'Returned' },
  scrap: { hi: 'कचरा', en: 'Scrap' },
  loss: { hi: 'घटत', en: 'Wastage' },
  balance: { hi: 'बाकी', en: 'Balance' },
  download: { hi: 'PDF डाउनलोड', en: 'Download PDF' },
  pending: { hi: 'बाकी', en: 'Pending' },
  approved: { hi: 'मंज़ूर', en: 'Approved' },
  rejected: { hi: 'नामंज़ूर', en: 'Rejected' },
  askAdvance: { hi: 'एडवांस माँगें', en: 'Request advance' },
  advanceTaken: { hi: 'एडवांस लिया (बाकी कटेगा)', en: 'Advance taken (to be adjusted)' },
  amount: { hi: 'रकम (₹)', en: 'Amount (₹)' },
} satisfies Record<string, Record<Lang, string>>

export type Key = keyof typeof dict

const I18nContext = createContext<{ lang: Lang; setLang: (l: Lang) => void; t: (k: Key) => string } | null>(null)

function initialLang(): Lang {
  try {
    const saved = localStorage.getItem('lang')
    if (saved === 'hi' || saved === 'en') return saved
  } catch {
    /* ignore */
  }
  return navigator.language.startsWith('en') ? 'en' : 'hi'
}

export function I18nProvider({ children, initial }: { children: ReactNode; initial?: string }) {
  const [lang, setLangState] = useState<Lang>(() => {
    try {
      if (localStorage.getItem('lang')) return initialLang()
    } catch {
      /* ignore */
    }
    return initial === 'en' || initial === 'hi' ? initial : initialLang()
  })

  const setLang = (l: Lang) => {
    setLangState(l)
    document.documentElement.lang = l
    try {
      localStorage.setItem('lang', l)
    } catch {
      /* ignore */
    }
  }

  return <I18nContext.Provider value={{ lang, setLang, t: (k) => dict[k][lang] }}>{children}</I18nContext.Provider>
}

export function useI18n() {
  const ctx = useContext(I18nContext)
  if (!ctx) throw new Error('useI18n outside provider')
  return ctx
}

export function LangToggle() {
  const { lang, setLang } = useI18n()
  return (
    <button
      className="rounded-full border border-current/30 px-3 py-1 text-sm font-medium"
      onClick={() => setLang(lang === 'hi' ? 'en' : 'hi')}
      aria-label="Change language"
    >
      {lang === 'hi' ? 'English' : 'हिन्दी'}
    </button>
  )
}
