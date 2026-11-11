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
  scrapWeight: { hi: 'कचरा / कटिंग (g)', en: 'Scrap / cuttings (g)' },
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
  owedToStore: { hi: 'दुकान को देना है', en: 'You owe the store' },
  noRequests: { hi: 'कोई अनुरोध नहीं', en: 'No requests yet' },
  amount: { hi: 'रकम (₹)', en: 'Amount (₹)' },
  // Head kaarigar → sub-kaarigar handoffs (blueprint §2).
  from: { hi: 'से मिला', en: 'From' },
  handoff: { hi: 'आपस में लेन-देन', en: 'Handoffs' },
  team: { hi: 'मेरी टीम', en: 'My team' },
  noTeam: { hi: 'आपके नीचे कोई कारीगर नहीं', en: 'No kaarigars under you' },
  giveToTeam: { hi: 'टीम को काम दें', en: 'Give to my team' },
  giveBack: { hi: 'वापस दें', en: 'Give back to' },
  chooseKaarigar: { hi: 'किसे देना है?', en: 'Give to whom?' },
  stage: { hi: 'काम (स्टेज)', en: 'Stage' },
  sameStage: { hi: 'यही काम', en: 'Same stage' },
  weightGive: { hi: 'कितना सोना दे रहे हैं (g)', en: 'Gold you are giving (g)' },
  handoffSent: { hi: 'दे दिया। उन्हें ऐप में मंज़ूर करना होगा।', en: 'Done. They must accept it in their app.' },
  onlineOnly: { hi: 'सोने का लेन-देन इंटरनेट पर ही होगा', en: 'Gold handoffs need internet' },
  givenTo: { hi: 'दिया', en: 'Given to' },
  update: { hi: 'काम की जानकारी', en: 'Update work' },
  markReady: { hi: 'काम तैयार', en: 'Work is ready' },
  photoProgress: { hi: 'काम की फ़ोटो', en: 'Photo of the work' },
  updated: { hi: 'भेज दिया', en: 'Saved' },
  moreReturn: { hi: 'और कुछ वापस? (कच्चा सोना, बुरादा, खराब नग)', en: 'Anything else? (raw gold, dust, rejected pieces)' },
  rawWeight: { hi: 'बचा कच्चा सोना (g)', en: 'Raw gold left (g)' },
  dustWeight: { hi: 'बुरादा / धूल (g)', en: 'Dust / filings (g)' },
  rejectedWeight: { hi: 'खराब नग का वज़न (g)', en: 'Rejected pieces weight (g)' },
  rejectedPieces: { hi: 'खराब नग (गिनती)', en: 'Rejected pieces (count)' },
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
      className="rounded-full border border-current/30 bg-white/10 px-3 py-1 text-sm font-medium transition-colors hover:bg-white/20"
      onClick={() => setLang(lang === 'hi' ? 'en' : 'hi')}
      aria-label="Change language"
    >
      {lang === 'hi' ? 'English' : 'हिन्दी'}
    </button>
  )
}
