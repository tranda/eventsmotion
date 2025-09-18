/**
 * Simplified Google Apps Script for Race Data Import
 *
 * This is a minimal version that focuses on simplicity and reliability.
 * Perfect for getting started with API key authentication.
 */

// === CONFIGURATION - UPDATE THESE VALUES ===
const CONFIG = {
  API_BASE_URL: 'https://your-domain.com/api', // Your Laravel API URL
  API_KEY: 'ak_your_api_key_here',              // Your API key from Laravel
  EVENT_ID: 1,                                  // Your event ID
  STATUS_CELL: 'A1'                            // Cell to update with status messages
};

/**
 * Simple function to import race data
 * Run this manually from the Google Apps Script editor
 */
function importRaceData() {
  try {
    // Get the active sheet
    const sheet = SpreadsheetApp.getActiveSheet();

    // Read race data (starting from row 2, assuming row 1 has headers)
    const range = sheet.getRange('A2:Q100'); // Adjust range as needed
    const values = range.getValues();

    const races = [];

    values.forEach(row => {
      // Skip empty rows
      if (!row[0] || !row[1] || !row[3]) return;

      // Build race data
      const race = {
        race_number: parseInt(row[0]),        // Column A: Race Number
        start_time: formatTime(row[1]),       // Column B: Start Time
        delay: formatTime(row[2]) || '0:00',  // Column C: Delay
        stage: row[3].toString(),             // Column D: Stage
        competition: row[4].toString(),       // Column E: Competition
        discipline_info: row[5].toString(),   // Column F: Discipline Info
        boat_size: row[6].toString().toLowerCase(), // Column G: Boat Size
        lanes: {}
      };

      // Extract lanes (H through Q = lanes 1-10)
      for (let i = 7; i <= 16; i++) {
        if (row[i] && row[i].toString().trim()) {
          const laneNumber = i - 6;
          race.lanes[laneNumber] = {
            team: row[i].toString().trim(),
            time: null
          };
        }
      }

      races.push(race);
    });

    if (races.length === 0) {
      updateStatus('No races found to import');
      return;
    }

    // Send to API
    const result = sendDataToAPI(races);

    if (result.success) {
      updateStatus(`✅ Success: Imported ${races.length} races at ${new Date().toLocaleTimeString()}`);
    } else {
      updateStatus(`❌ Error: ${result.message || 'Unknown error'}`);
    }

  } catch (error) {
    updateStatus(`❌ Script Error: ${error.message}`);
    console.error('Import error:', error);
  }
}

/**
 * Send data to the Laravel API
 */
function sendDataToAPI(races) {
  const url = `${CONFIG.API_BASE_URL}/race-results/bulk-update`;

  const payload = {
    event_id: CONFIG.EVENT_ID,
    races: races
  };

  const options = {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'X-API-Key': CONFIG.API_KEY,
      'Accept': 'application/json'
    },
    payload: JSON.stringify(payload),
    muteHttpExceptions: true // Don't throw on HTTP errors
  };

  console.log('Sending to:', url);
  console.log('Data:', JSON.stringify(payload, null, 2));

  const response = UrlFetchApp.fetch(url, options);
  const responseCode = response.getResponseCode();
  const responseText = response.getContentText();

  console.log('Response:', responseCode, responseText);

  if (responseCode === 200) {
    return JSON.parse(responseText);
  } else {
    // Handle error responses
    try {
      const errorData = JSON.parse(responseText);
      return {
        success: false,
        message: errorData.message || `HTTP ${responseCode} error`
      };
    } catch (parseError) {
      return {
        success: false,
        message: `HTTP ${responseCode}: ${responseText}`
      };
    }
  }
}

/**
 * Format time values
 */
function formatTime(value) {
  if (!value) return '0:00';

  if (value instanceof Date) {
    const hours = value.getHours();
    const minutes = value.getMinutes();
    return `${hours}:${minutes.toString().padStart(2, '0')}`;
  }

  return value.toString();
}

/**
 * Update status in the sheet
 */
function updateStatus(message) {
  try {
    const sheet = SpreadsheetApp.getActiveSheet();
    sheet.getRange(CONFIG.STATUS_CELL).setValue(message);
    console.log('Status updated:', message);
  } catch (error) {
    console.error('Could not update status:', error);
  }
}

/**
 * Test your API key and connection
 */
function testAPIConnection() {
  try {
    const url = `${CONFIG.API_BASE_URL}/race-results/bulk-update`;

    const options = {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-API-Key': CONFIG.API_KEY,
        'Accept': 'application/json'
      },
      payload: JSON.stringify({
        event_id: CONFIG.EVENT_ID,
        races: [] // Empty array to test authentication
      }),
      muteHttpExceptions: true
    };

    const response = UrlFetchApp.fetch(url, options);
    const responseCode = response.getResponseCode();
    const responseText = response.getContentText();

    if (responseCode === 200) {
      updateStatus('✅ API connection successful');
      console.log('API test successful');
    } else if (responseCode === 401) {
      updateStatus('❌ Invalid API key');
      console.error('Authentication failed - check your API key');
    } else if (responseCode === 403) {
      updateStatus('❌ API key lacks required permissions');
      console.error('Permission denied - API key needs races.bulk-update permission');
    } else {
      updateStatus(`❌ API error: HTTP ${responseCode}`);
      console.error('API test failed:', responseCode, responseText);
    }

  } catch (error) {
    updateStatus(`❌ Connection error: ${error.message}`);
    console.error('Connection test error:', error);
  }
}